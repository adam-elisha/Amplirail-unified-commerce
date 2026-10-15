<?php

declare(strict_types=1);

namespace Modules\PlatformFees\Domain;

/**
 * A partner's markup on a merchant's sales (SaaS platform, ISO or reseller): a percentage of gross sales plus a fixed
 * amount per approved sale, rounded half-up once per statement. Deducted on the merchant's payout statement and owed
 * to the partner (ledger account partner_payable:{partner_key}).
 */
final class PlatformFee
{
    public static function amount(int $grossMinor, int $approvedCount, int $percentBps, int $fixedMinor): int
    {
        $pct = intdiv($grossMinor * $percentBps * 2 + 10000, 20000);

        return max(0, $pct + $approvedCount * $fixedMinor);
    }

    public static function validKey(string $key): bool
    {
        return preg_match('/^[a-z0-9_]{2,60}$/', $key) === 1;
    }
}
