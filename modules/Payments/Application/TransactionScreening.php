<?php

declare(strict_types=1);

namespace Modules\Payments\Application;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Launch-slice screening with the same rules as core AmpliRail\Screening\Screening (blocked token, max ticket, velocity
 * per token per hour), but with velocity counters in the shared cache (Redis) so every API container sees the same count.
 */
final class TransactionScreening
{
    public function __construct(private readonly Cache $cache) {}

    /**
     * @param  array{max_ticket_minor:int, max_per_token_per_hour:int, blocked_tokens?:list<string>}  $rules
     * @return array{allow:bool, reason?:string}
     */
    public function check(string $merchantId, string $paymentToken, int $amountMinor, array $rules): array
    {
        if (in_array($paymentToken, $rules['blocked_tokens'] ?? [], true)) {
            return ['allow' => false, 'reason' => 'blocked_token'];
        }
        if ($amountMinor > $rules['max_ticket_minor']) {
            return ['allow' => false, 'reason' => 'over_max_ticket'];
        }
        $bucket = intdiv(time(), 3600);
        $key = 'velocity:'.$merchantId.':'.hash('sha256', $paymentToken).':'.$bucket;
        $this->cache->add($key, 0, 7200);
        $count = (int) $this->cache->increment($key);
        if ($count > $rules['max_per_token_per_hour']) {
            return ['allow' => false, 'reason' => 'velocity'];
        }

        return ['allow' => true];
    }
}
