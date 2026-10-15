<?php

declare(strict_types=1);

namespace Modules\RoutePolicy\Application;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\RoutePolicy\Contracts\RouteContext;
use Modules\RoutePolicy\Contracts\RoutePolicy;
use Modules\RoutePolicy\Domain\BinPreference;
use Modules\RoutePolicy\Domain\MidCap;
use Modules\RoutePolicy\Infrastructure\BinRange;
use Modules\RoutePolicy\Infrastructure\BinRoutePreference;
use Modules\RoutePolicy\Infrastructure\MidControl;

/**
 * MID caps and pause, and BIN-based ordering of the merchant's own MIDs. Captured volume per MID for today and this
 * month is read from transactions and cached for 60 seconds (good enough for caps set with a safety margin).
 */
final class RoutePolicyService implements RoutePolicy
{
    public function __construct(private readonly Cache $cache) {}

    public function order(array $candidates, RouteContext $ctx): array
    {
        if ($candidates === [] || ! $this->ready()) {
            return $candidates;
        }
        $controls = MidControl::query()->whereIn('merchant_account_id', array_map(fn ($m) => $m->id, $candidates))->get()->keyBy('merchant_account_id');
        $open = [];
        foreach ($candidates as $m) {
            $c = $controls[$m->id] ?? null;
            if ($c === null) {
                $open[] = $m;

                continue;
            }
            [$today, $month] = $this->volume($m->id);
            if (MidCap::allows($c->paused, $c->daily_cap_minor, $c->monthly_cap_minor, $today, $month, $ctx->amountMinor)) {
                $open[] = $m;
            }
        }

        $binInfo = $ctx->bin !== null ? $this->binInfo($ctx->bin) : [];
        $prefs = BinRoutePreference::query()->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('merchant_id')->orWhere('merchant_id', $ctx->merchantId))->get()
            ->map(fn ($p) => new BinPreference($p->id, $p->merchant_id, $p->bin_prefix, $p->card_brand, $p->card_type, $p->issuer_country,
                $p->commercial, array_values((array) $p->preferred_connectors), (int) $p->priority))
            ->filter(fn (BinPreference $p) => $p->matches($ctx, $binInfo))
            ->sort(fn (BinPreference $a, BinPreference $b) => [$a->priority, -$a->specificity()] <=> [$b->priority, -$b->specificity()])
            ->values();

        return $prefs->isEmpty() ? $open : $prefs->first()->apply($open);
    }

    /** @return array{0:int,1:int} captured minor units today and this month (UTC) */
    private function volume(string $maccId): array
    {
        return $this->cache->remember('midvol:'.$maccId.':'.now()->format('Ymd'), 60, function () use ($maccId): array {
            $row = DB::table('transactions')->where('merchant_account_id', $maccId)->where('status', 'approved')
                ->where('created_at', '>=', now()->startOfMonth())
                ->selectRaw('coalesce(sum(captured_minor),0) as month, coalesce(sum(case when created_at >= ? then captured_minor else 0 end),0) as today', [now()->startOfDay()])
                ->first();

            return [(int) ($row->today ?? 0), (int) ($row->month ?? 0)];
        });
    }

    /** @return array{card_brand?:string|null, card_type?:string|null, issuer_country?:string|null, commercial?:bool|null} */
    private function binInfo(string $bin): array
    {
        for ($len = min(8, strlen($bin)); $len >= 6; $len--) {
            $r = BinRange::query()->find(substr($bin, 0, $len));
            if ($r !== null) {
                return ['card_brand' => $r->card_brand, 'card_type' => $r->card_type, 'issuer_country' => $r->issuer_country, 'commercial' => (bool) $r->commercial];
            }
        }

        return [];
    }

    private function ready(): bool
    {
        return (bool) $this->cache->remember('route_policy:ready', 300, fn (): bool => Schema::hasTable('mid_controls') && Schema::hasTable('bin_route_preferences'));
    }
}
