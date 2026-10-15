<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Support\Ids;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Audit\Contracts\AuditTrail;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\RoutePolicy\Infrastructure\BinRange;
use Modules\RoutePolicy\Infrastructure\BinRoutePreference;

/** BIN routing: which processors to try first for which cards, and the BIN reference table. */
final class BinRoutingController extends AdminController
{
    public function index(Request $request): View
    {
        return view('admin.routing2.bins', [
            'prefs' => BinRoutePreference::query()->orderBy('priority')->orderBy('name')->get(),
            'binCount' => BinRange::query()->count(),
            'merchants' => Merchant::query()->orderBy('legal_name')->get()->mapWithKeys(fn ($m) => [$m->id => $m->displayName()]),
            'connectors' => array_keys(array_filter((array) config('connectors', []), fn ($c) => is_array($c) && isset($c['operations']))),
            'canEdit' => $this->can($request, 'routing.draft'),
        ]);
    }

    public function store(Request $request, AuditTrail $audit): RedirectResponse
    {
        $this->requirePermission($request, 'routing.draft');
        $v = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'merchant_id' => ['nullable', 'string', 'max:40'],
            'bin_prefix' => ['nullable', 'regex:/^\d{1,8}$/'], 'card_brand' => ['nullable', 'in:visa,mastercard,amex,discover'],
            'card_type' => ['nullable', 'in:credit,debit,prepaid'], 'issuer_country' => ['nullable', 'regex:/^[A-Za-z]{2}$/'],
            'commercial' => ['nullable', 'in:yes,no'], 'connectors' => ['required', 'array', 'min:1'], 'connectors.*' => ['string', 'max:40'],
            'priority' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);
        $p = BinRoutePreference::query()->create([
            'id' => Ids::new('brp'), 'name' => $v['name'], 'merchant_id' => ($v['merchant_id'] ?? '') ?: null,
            'bin_prefix' => ($v['bin_prefix'] ?? '') ?: null, 'card_brand' => $v['card_brand'] ?? null, 'card_type' => $v['card_type'] ?? null,
            'issuer_country' => isset($v['issuer_country']) ? strtoupper($v['issuer_country']) : null,
            'commercial' => isset($v['commercial']) ? $v['commercial'] === 'yes' : null,
            'preferred_connectors' => array_values(array_unique(array_filter($v['connectors']))), 'priority' => (int) $v['priority'],
            'status' => 'active', 'created_by' => $this->userId(),
        ]);
        $audit->record('bin_route_preference.created', 'bin_route_preference', $p->id, null, $p->toArray(), $this->actor());

        return back()->with('status', 'Preference added. It orders each merchant\'s own MIDs only; it never adds a MID or reroutes a decline.');
    }

    public function toggle(Request $request, string $pref, AuditTrail $audit): RedirectResponse
    {
        $this->requirePermission($request, 'routing.draft');
        $p = BinRoutePreference::query()->whereKey($pref)->firstOrFail();
        $p->forceFill(['status' => $p->status === 'active' ? 'paused' : 'active'])->save();
        $audit->record('bin_route_preference.'.$p->status, 'bin_route_preference', $p->id, null, ['status' => $p->status], $this->actor());

        return back()->with('status', 'Preference '.$p->status.'.');
    }

    /** CSV header: bin_prefix, card_brand, card_type, card_tier, issuer_name, issuer_country, commercial. Prefixes only. */
    public function import(Request $request): RedirectResponse
    {
        $this->requirePermission($request, 'routing.draft');
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:20480']]);
        $fh = fopen((string) $request->file('file')?->getRealPath(), 'r');
        if ($fh === false) {
            return back()->with('error', 'The uploaded file could not be read.');
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), (array) fgetcsv($fh));
        if (! in_array('bin_prefix', $header, true)) {
            return back()->with('error', 'The CSV needs a bin_prefix column.');
        }
        $n = 0;
        $skipped = 0;
        while (($row = fgetcsv($fh)) !== false) {
            $r = array_combine($header, array_pad($row, count($header), ''));
            $bin = preg_replace('/\D/', '', (string) $r['bin_prefix']);
            if (! preg_match('/^\d{6,8}$/', (string) $bin)) {
                $skipped++; // never accept anything longer than 8 digits

                continue;
            }
            BinRange::query()->updateOrCreate(['bin_prefix' => $bin], [
                'card_brand' => strtolower(trim((string) ($r['card_brand'] ?? ''))) ?: null,
                'card_type' => strtolower(trim((string) ($r['card_type'] ?? ''))) ?: null,
                'card_tier' => trim((string) ($r['card_tier'] ?? '')) ?: null,
                'issuer_name' => mb_substr(trim((string) ($r['issuer_name'] ?? '')), 0, 120) ?: null,
                'issuer_country' => strtoupper(substr(trim((string) ($r['issuer_country'] ?? '')), 0, 2)) ?: null,
                'commercial' => in_array(strtolower(trim((string) ($r['commercial'] ?? ''))), ['1', 'true', 'yes', 'y'], true),
            ]);
            $n++;
        }
        fclose($fh);

        return back()->with('status', "Imported $n BIN ranges".($skipped > 0 ? " (skipped $skipped rows that were not 6 to 8 digits)." : '.'));
    }
}
