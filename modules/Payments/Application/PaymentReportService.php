<?php

declare(strict_types=1);

namespace Modules\Payments\Application;

use Modules\Payments\Contracts\PaymentReports;
use Modules\Payments\Infrastructure\Refund;
use Modules\Payments\Infrastructure\Transaction;
use Modules\Payments\Infrastructure\TransactionAttempt;

final class PaymentReportService implements PaymentReports
{
    public function connectorHealth(int $minutes = 60): array
    {
        return array_values(TransactionAttempt::query()->where('created_at', '>=', now()->subMinutes($minutes))
            ->selectRaw("connector, count(*) as attempts, sum(case when outcome = 'error' then 1 else 0 end) as errors, avg(latency_ms) as latency")
            ->groupBy('connector')->get()
            ->map(fn ($r) => [
                'connector' => (string) $r->getAttribute('connector'),
                'attempts' => (int) $r->getAttribute('attempts'),
                'errors' => (int) $r->getAttribute('errors'),
                'error_rate' => (int) $r->getAttribute('attempts') > 0 ? round((int) $r->getAttribute('errors') * 100 / (int) $r->getAttribute('attempts'), 1) : null,
                'avg_latency_ms' => $r->getAttribute('latency') !== null ? (int) round((float) $r->getAttribute('latency')) : null,
            ])->all());
    }

    public function volumeByConnector(int $days = 30): array
    {
        return array_values(Transaction::query()->where('created_at', '>=', now()->subDays($days))->where('status', 'approved')
            ->selectRaw('connector, currency, environment, sum(captured_minor) as amount, count(*) as n')
            ->groupBy('connector', 'currency', 'environment')->get()
            ->map(fn ($r) => [
                'connector' => (string) $r->getAttribute('connector'),
                'currency' => (string) $r->getAttribute('currency'),
                'environment' => (string) $r->getAttribute('environment'),
                'amount_minor' => (int) $r->getAttribute('amount'),
                'count' => (int) $r->getAttribute('n'),
            ])->all());
    }

    public function approvalRate(int $days = 30, ?string $environment = null): array
    {
        $rows = Transaction::query()->where('created_at', '>=', now()->subDays($days))
            ->when($environment !== null, fn ($q) => $q->where('environment', $environment))
            ->whereIn('status', ['approved', 'declined'])->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $approved = (int) ($rows['approved'] ?? 0);
        $declined = (int) ($rows['declined'] ?? 0);

        return ['approved' => $approved, 'declined' => $declined, 'approval_rate' => $approved + $declined > 0 ? round($approved * 100 / ($approved + $declined), 1) : null];
    }

    public function statementActivity(string $merchantAccountId, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $approved = Transaction::query()->where('merchant_account_id', $merchantAccountId)->where('status', 'approved')
            ->whereIn('state', ['captured', 'partially_refunded', 'refunded'])->where('created_at', '>=', $from)->where('created_at', '<', $to);
        $refunds = Refund::query()->where('status', 'succeeded')->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->whereIn('transaction_id', Transaction::query()->where('merchant_account_id', $merchantAccountId)->select('id'));

        return [
            'approved_count' => (clone $approved)->count(),
            'gross_minor' => (int) (clone $approved)->sum('captured_minor'),
            'declined_count' => Transaction::query()->where('merchant_account_id', $merchantAccountId)->where('status', 'declined')
                ->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(),
            'refund_count' => (clone $refunds)->count(),
            'refund_minor' => (int) (clone $refunds)->sum('amount_minor'),
        ];
    }

    public function merchantSummary(string $merchantId, int $days, string $environment): array
    {
        $row = Transaction::query()->where('merchant_id', $merchantId)->where('environment', $environment)->where('status', 'approved')
            ->where('created_at', '>=', now()->subDays($days))->selectRaw('count(*) as n, sum(captured_minor) as amount, max(currency) as currency')->first();

        return [
            'count' => (int) ($row?->getAttribute('n') ?? 0),
            'amount_minor' => (int) ($row?->getAttribute('amount') ?? 0),
            'currency' => $row?->getAttribute('currency') !== null ? (string) $row->getAttribute('currency') : null,
        ];
    }
}
