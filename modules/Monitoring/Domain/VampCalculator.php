<?php

declare(strict_types=1);

namespace Modules\Monitoring\Domain;

/**
 * Visa Acquirer Monitoring Program (VAMP) arithmetic, as published by Visa for 2025-2026:
 *   ratio = (TC40 fraud reports + TC15 disputes) / settled card-not-present Visa transactions, per calendar month.
 *   A transaction with both a fraud report and a dispute is counted once.
 *   Merchant "excessive": ratio >= 1.5% (US, Canada, EU, Asia Pacific, from April 2026) or 2.2% (other regions),
 *   and at least 1,500 fraud + dispute events in the month.
 * Thresholds change: keep them in one place and confirm them with the acquirer before relying on them.
 */
final class VampCalculator
{
    /** @var array<string,int> merchant excessive threshold in basis points per region group */
    public const MERCHANT_THRESHOLD_BPS = ['us_ca_eu_ap' => 150, 'other' => 220];

    public const MIN_COUNT = 1500;

    /** Early warning at 75% of the threshold, so Risk can act before the network does. */
    public const WARNING_SHARE = 0.75;

    public const ACQUIRER_ABOVE_STANDARD_BPS = 50;

    public const ACQUIRER_EXCESSIVE_BPS = 70;

    /**
     * @param  list<string>  $fraudTxnIds  transactions with a TC40 in the month
     * @param  list<string>  $disputeTxnIds  transactions with a dispute (TC15) opened in the month
     * @return array{count:int, ratio_bps:int}
     */
    public static function ratio(array $fraudTxnIds, array $disputeTxnIds, int $unmatchedFraud, int $settledCnp): array
    {
        $count = count(array_unique(array_merge($fraudTxnIds, $disputeTxnIds))) + $unmatchedFraud;
        $bps = $settledCnp > 0 ? intdiv($count * 10000 + intdiv($settledCnp, 2), $settledCnp) : ($count > 0 ? 10000 : 0);

        return ['count' => $count, 'ratio_bps' => $bps];
    }

    /** @return 'ok'|'warning'|'over_ratio_below_minimum'|'excessive' */
    public static function merchantStatus(int $ratioBps, int $count, string $region = 'us_ca_eu_ap'): string
    {
        $threshold = self::MERCHANT_THRESHOLD_BPS[$region] ?? self::MERCHANT_THRESHOLD_BPS['us_ca_eu_ap'];
        if ($ratioBps >= $threshold) {
            return $count >= self::MIN_COUNT ? 'excessive' : 'over_ratio_below_minimum';
        }

        return $ratioBps >= (int) floor($threshold * self::WARNING_SHARE) ? 'warning' : 'ok';
    }

    /** @return 'ok'|'above_standard'|'excessive' */
    public static function portfolioStatus(int $ratioBps): string
    {
        return match (true) {
            $ratioBps >= self::ACQUIRER_EXCESSIVE_BPS => 'excessive',
            $ratioBps >= self::ACQUIRER_ABOVE_STANDARD_BPS => 'above_standard',
            default => 'ok',
        };
    }

    public static function format(int $bps): string
    {
        return number_format($bps / 100, 2).'%';
    }
}
