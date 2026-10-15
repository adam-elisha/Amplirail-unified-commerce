<?php

declare(strict_types=1);

namespace Modules\RoutePolicy\Domain;

/** Whether a MID can take one more payment of this amount under its daily and monthly caps (null cap = unlimited). */
final class MidCap
{
    public static function allows(bool $paused, ?int $dailyCap, ?int $monthlyCap, int $todayMinor, int $monthMinor, int $amountMinor): bool
    {
        if ($paused) {
            return false;
        }
        if ($dailyCap !== null && $todayMinor + $amountMinor > $dailyCap) {
            return false;
        }

        return ! ($monthlyCap !== null && $monthMinor + $amountMinor > $monthlyCap);
    }
}
