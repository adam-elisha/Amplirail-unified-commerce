<?php
declare(strict_types=1);
namespace AmpliRail\Payouts;

/**
 * A signed, versioned fee schedule (Section 9.2). Loaded from the database in production; from config/*.json in tests.
 * Bases: percent_gross | per_approved | per_refund | per_chargeback | per_retrieval | per_representment
 *        | monthly (first statement of the month only) | per_statement | payout_method
 * Amounts: "bps" for percent (850 = 8.50%), "minor" for flat amounts in minor units.
 * Optional tiers by chargeback ratio: [{"max_ratio_bps": 100, "minor": 3500}, {"max_ratio_bps": null, "minor": 7500}]
 */
final class FeeSchedule
{
    /** @param array<int,array<string,mixed>> $lines */
    public function __construct(
        public readonly string $version,
        public readonly string $currency,
        public readonly array $lines,
        public readonly int $securityReserveBps,
        public readonly array $payoutMethodFees,   // ["ach"=>["minor"=>0], "wire_intl"=>["minor"=>7500], "usdt"=>["bps"=>150]]
    ) {}

    public static function fromArray(array $a): self
    {
        return new self($a['version'], $a['currency'], $a['lines'], (int)$a['security_reserve_bps'], $a['payout_method_fees']);
    }
}
