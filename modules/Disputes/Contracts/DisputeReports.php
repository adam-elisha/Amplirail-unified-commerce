<?php

declare(strict_types=1);

namespace Modules\Disputes\Contracts;

/** Read-only dispute aggregates for payout statements and reconciliation (current tenant). */
interface DisputeReports
{
    /**
     * Dispute activity for one MID in [from, to) (UTC datetimes).
     * chargeback_* counts cases opened in the window at a chargeback stage; retrieval_count counts inquiries;
     * representment_count counts responses submitted in the window; reversal_minor is money returned by wins.
     *
     * @return array{chargeback_count:int, chargeback_minor:int, retrieval_count:int, representment_count:int, reversal_minor:int}
     */
    public function activity(string $merchantAccountId, \DateTimeInterface $from, \DateTimeInterface $to): array;
}
