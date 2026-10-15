<?php
declare(strict_types=1);
namespace AmpliRail\Payouts;

use AmpliRail\Money\Money;

/**
 * Section 9.9 rules:
 * - Each line rounds half-up at the minor unit; totals are the sum of lines (so statements always add up).
 * - Monthly fees only on the first statement of the month.
 * - Chargeback ratio = chargebacks / settled sales (approved count), not including declines.
 * - Payout method percent fee applies to the net before that fee.
 */
final class PayoutStatementCalculator
{
    /** @return array{lines: array<int,array{section:string,label:string,amount:Money}>, gross:Money, fees:Money, reserve:Money, net:Money, cb_ratio_bps:int} */
    public function calculate(StatementInput $in, FeeSchedule $s): array
    {
        $c = $s->currency;
        $gross = Money::of($in->grossMinor, $c);
        $ratioBps = $in->approvedCount > 0 ? Money::roundHalfUpDiv($in->chargebackCount * 10000, $in->approvedCount) : 0;
        $lines = [];
        $add = function (string $section, string $label, Money $m) use (&$lines) { if ($m->minor !== 0) { $lines[] = ['section' => $section, 'label' => $label, 'amount' => $m]; } };

        $add('activity', 'Refunds', Money::of(-$in->refundMinor, $c));
        $add('activity', 'Chargebacks', Money::of(-$in->chargebackMinor, $c));

        $fees = Money::of(0, $c);
        foreach ($s->lines as $l) {
            $count = match ($l['basis']) {
                'percent_gross' => null,
                'per_approved' => $in->approvedCount,
                'per_refund' => $in->refundCount,
                'per_chargeback' => $in->chargebackCount,
                'per_retrieval' => $in->retrievalCount,
                'per_representment' => $in->representmentCount,
                'monthly' => $in->firstStatementOfMonth ? 1 : 0,
                'per_statement' => 1,
                default => throw new \InvalidArgumentException('Unknown basis ' . $l['basis']),
            };
            if ($l['basis'] === 'percent_gross') {
                $amt = $gross->percentBps((int)$l['bps']);
            } else {
                $unit = isset($l['tiers']) ? self::tierMinor($l['tiers'], $ratioBps) : (int)$l['minor'];
                $amt = Money::of($unit, $c)->times($count);
            }
            $fee = $amt->negate();
            $fees = $fees->plus($fee);
            $add($l['section'], $l['label'], $fee);
        }

        $reserve = $gross->percentBps($s->securityReserveBps)->negate();
        $add('reserve', 'Security reserve', $reserve);

        $netBefore = $gross->minus(Money::of($in->refundMinor + $in->chargebackMinor, $c))->plus($fees)->plus($reserve);
        $pm = $s->payoutMethodFees[$in->payoutMethod] ?? ['minor' => 0];
        $pmFee = isset($pm['bps']) ? $netBefore->percentBps((int)$pm['bps'])->negate() : Money::of(-(int)$pm['minor'], $c);
        $add('payout', 'Payout method fee (' . $in->payoutMethod . ')', $pmFee);
        $fees = $fees->plus($pmFee);

        return ['lines' => $lines, 'gross' => $gross, 'fees' => $fees->negate(), 'reserve' => $reserve->negate(), 'net' => $netBefore->plus($pmFee), 'cb_ratio_bps' => $ratioBps];
    }

    private static function tierMinor(array $tiers, int $ratioBps): int
    {
        foreach ($tiers as $t) { if ($t['max_ratio_bps'] === null || $ratioBps <= (int)$t['max_ratio_bps']) { return (int)$t['minor']; } }
        return (int)end($tiers)['minor'];
    }
}
