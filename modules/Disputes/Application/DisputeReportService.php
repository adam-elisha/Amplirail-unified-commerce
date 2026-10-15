<?php

declare(strict_types=1);

namespace Modules\Disputes\Application;

use Modules\Disputes\Contracts\DisputeReports;
use Modules\Disputes\Infrastructure\Dispute;

final class DisputeReportService implements DisputeReports
{
    public function activity(string $merchantAccountId, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $base = fn () => Dispute::query()->where('merchant_account_id', $merchantAccountId);
        $opened = $base()->where('created_at', '>=', $from)->where('created_at', '<', $to);

        return [
            'chargeback_count' => (clone $opened)->where('stage', '!=', 'inquiry')->count(),
            'chargeback_minor' => (int) (clone $opened)->where('stage', '!=', 'inquiry')->sum('amount_minor'),
            'retrieval_count' => (clone $opened)->where('stage', 'inquiry')->count(),
            'representment_count' => $base()->whereNotNull('responded_at')->where('responded_at', '>=', $from)->where('responded_at', '<', $to)->count(),
            'reversal_minor' => (int) $base()->where('status', 'won')->where('stage', '!=', 'inquiry')
                ->where('resolved_at', '>=', $from)->where('resolved_at', '<', $to)->sum('amount_minor'),
        ];
    }
}
