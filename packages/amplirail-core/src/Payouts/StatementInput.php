<?php
declare(strict_types=1);
namespace AmpliRail\Payouts;

/** Activity for one MID and period, read from the ledger and disputes module (never typed in). */
final class StatementInput
{
    public function __construct(
        public readonly string $merchantName,
        public readonly string $mid,
        public readonly string $periodStart,       // Y-m-d
        public readonly string $periodEnd,
        public readonly int $approvedCount,
        public readonly int $grossMinor,
        public readonly int $declinedCount,        // information only
        public readonly int $refundCount,
        public readonly int $refundMinor,
        public readonly int $chargebackCount,
        public readonly int $chargebackMinor,
        public readonly int $retrievalCount,
        public readonly int $representmentCount,
        public readonly bool $firstStatementOfMonth,
        public readonly string $payoutMethod,      // ach | wire_intl | usdt | ...
    ) {}
}
