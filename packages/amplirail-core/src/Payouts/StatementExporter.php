<?php
declare(strict_types=1);
namespace AmpliRail\Payouts;

/** CSV export of a calculated statement (PDF and Excel are rendered by the app from the same data). */
final class StatementExporter
{
    public static function csv(StatementInput $in, array $r, string $statementId): string
    {
        $fmt = fn(int $m) => ($m < 0 ? '-' : '') . intdiv(abs($m), 100) . '.' . str_pad((string)(abs($m) % 100), 2, '0', STR_PAD_LEFT);
        $rows = [['statement_id', 'merchant', 'mid', 'period_start', 'period_end', 'section', 'line', 'amount', 'currency']];
        $base = [$statementId, $in->merchantName, $in->mid, $in->periodStart, $in->periodEnd];
        $cur = $r['gross']->currency;
        $rows[] = array_merge($base, ['activity', 'Gross sales (' . $in->approvedCount . ' approved)', $fmt($r['gross']->minor), $cur]);
        foreach ($r['lines'] as $l) $rows[] = array_merge($base, [$l['section'], $l['label'], $fmt($l['amount']->minor), $cur]);
        $rows[] = array_merge($base, ['total', 'Net payout', $fmt($r['net']->minor), $cur]);
        $out = fopen('php://temp', 'r+');
        foreach ($rows as $row) fputcsv($out, $row, ',', '"', '\\');
        rewind($out); return stream_get_contents($out);
    }
}
