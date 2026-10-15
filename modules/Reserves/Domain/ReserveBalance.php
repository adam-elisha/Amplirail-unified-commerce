<?php

declare(strict_types=1);

namespace Modules\Reserves\Domain;

/**
 * Reserve held for a merchant, read from the ledger account reserve:{merchant}. Payout statements credit that account
 * (negative balance = money held). A release can never exceed what is held.
 */
final class ReserveBalance
{
    public static function heldFromLedgerBalance(int $ledgerBalanceMinor): int
    {
        return max(0, -$ledgerBalanceMinor);
    }

    public static function assertReleasable(int $heldMinor, int $releaseMinor): void
    {
        if ($releaseMinor < 1) {
            throw new \InvalidArgumentException('The release amount must be more than zero.');
        }
        if ($releaseMinor > $heldMinor) {
            throw new \InvalidArgumentException('The release amount is more than the reserve held.');
        }
    }
}
