<?php

declare(strict_types=1);

namespace Modules\Velocity\Application;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;
use Modules\Velocity\Contracts\VelocityCheck;
use Modules\Velocity\Contracts\VelocityInput;
use Modules\Velocity\Domain\VelocityRule;
use Modules\Velocity\Infrastructure\VelocityRuleRecord;

/**
 * Evaluates the tenant's active velocity rules with counters in the shared cache (Redis in production), so every API
 * container sees the same counts. All matching rules are checked first; counters move only when the attempt is
 * allowed. "monitor" rules never block: they log a velocity.monitor_hit for Risk.
 */
final class VelocityEngine implements VelocityCheck
{
    /** @var list<VelocityRule>|null */
    private ?array $rules = null;

    public function __construct(private readonly Cache $cache) {}

    public function check(VelocityInput $in): ?string
    {
        return $this->evaluate($in, array_values(array_filter($this->rules(), fn (VelocityRule $r) => $r->scope !== 'connector' && $r->applies($in))));
    }

    public function allowsConnector(VelocityInput $in, string $connector): bool
    {
        return $this->evaluate($in, array_values(array_filter($this->rules(), fn (VelocityRule $r) => $r->scope === 'connector' && $r->applies($in, $connector)))) === null;
    }

    /** @param list<VelocityRule> $rules */
    private function evaluate(VelocityInput $in, array $rules): ?string
    {
        $now = time();
        foreach ($rules as $r) {
            $current = $r->metric === 'single_amount' ? 0 : (int) $this->cache->get($r->counterKey($in, $now), 0);
            if (! $r->fits($current, $in)) {
                if ($r->blocks()) {
                    Log::warning('velocity.blocked', ['rule' => $r->id, 'merchant' => $in->merchantId]);

                    return $r->name;
                }
                Log::info('velocity.monitor_hit', ['rule' => $r->id, 'merchant' => $in->merchantId]);
            }
        }
        foreach ($rules as $r) {
            if ($r->metric === 'single_amount') {
                continue;
            }
            $key = $r->counterKey($in, $now);
            $this->cache->add($key, 0, max(120, $r->windowMinutes * 60 * 2));
            $this->cache->increment($key, $r->increment($in));
        }

        return null;
    }

    /** @return list<VelocityRule> */
    private function rules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }
        // Until the velocity_rules migration has run there are no rules; payments must not fail because of that.
        if (! \Illuminate\Support\Facades\Schema::hasTable('velocity_rules')) {
            return $this->rules = [];
        }

        return $this->rules = VelocityRuleRecord::query()->where('status', 'active')->get()
            ->map(fn (VelocityRuleRecord $r) => $r->toDomain())->values()->all();
    }
}
