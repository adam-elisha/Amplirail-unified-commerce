<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

/** Read-only aggregates for dashboards (current tenant; merchant-scoped automatically for merchant callers). */
interface PaymentReports
{
    /** @return list<array{connector:string, attempts:int, errors:int, error_rate:float|null, avg_latency_ms:int|null}> */
    public function connectorHealth(int $minutes = 60): array;

    /** @return list<array{connector:string, currency:string, environment:string, amount_minor:int, count:int}> */
    public function volumeByConnector(int $days = 30): array;

    /** @return array{approved:int, declined:int, approval_rate:float|null} */
    public function approvalRate(int $days = 30, ?string $environment = null): array;

    /** @return array{count:int, amount_minor:int, currency:?string} approved sales for one merchant in the window */
    public function merchantSummary(string $merchantId, int $days, string $environment): array;

    /**
     * Statement activity for one MID in [from, to) (UTC datetimes), from transactions and refunds.
     *
     * @return array{approved_count:int, gross_minor:int, declined_count:int, refund_count:int, refund_minor:int}
     */
    public function statementActivity(string $merchantAccountId, \DateTimeInterface $from, \DateTimeInterface $to): array;
}
