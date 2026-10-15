<?php

declare(strict_types=1);

namespace Modules\Reconciliation\Domain;

/**
 * Compares what the processor deposited with what AmpliRail expected for the same MID and period.
 * Expected = captured sales - refunds - chargebacks + chargeback wins (all in the MID's currency, integer minor units).
 * Any difference, even one cent, is an exception for Finance: the processor may have taken fees, a reserve or an
 * adjustment we did not record, and that must be explained, not hidden.
 */
final class DepositMatch
{
    private function __construct(
        public readonly int $expectedMinor,
        public readonly int $receivedMinor,
        public readonly int $differenceMinor,
        public readonly string $status,
    ) {}

    public static function expected(int $grossMinor, int $refundMinor, int $chargebackMinor, int $reversalMinor): int
    {
        return $grossMinor - $refundMinor - $chargebackMinor + $reversalMinor;
    }

    public static function compare(int $expectedMinor, int $receivedMinor): self
    {
        $diff = $receivedMinor - $expectedMinor;

        return new self($expectedMinor, $receivedMinor, $diff, $diff === 0 ? 'matched' : 'exception');
    }
}
