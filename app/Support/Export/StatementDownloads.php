<?php

declare(strict_types=1);

namespace App\Support\Export;

use Dompdf\Dompdf;
use Dompdf\Options;
use Modules\Settlement\Application\StatementService;
use Modules\Settlement\Infrastructure\PayoutStatement;
use Symfony\Component\HttpFoundation\Response;

/** Statement downloads: CSV (core exporter), PDF (dompdf, no remote resources) and Excel. Same numbers in every format. */
final class StatementDownloads
{
    public function __construct(private readonly StatementService $statements) {}

    public function download(PayoutStatement $s, string $format): Response
    {
        $name = 'payout-statement-'.$s->id;

        return match ($format) {
            'csv' => response($this->statements->csv($s), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"$name.csv\""]),
            'xlsx' => response(SimpleXlsx::build($this->rows($s)), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => "attachment; filename=\"$name.xlsx\""]),
            'pdf' => response($this->pdf($s), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"$name.pdf\""]),
            default => throw new \InvalidArgumentException('Unknown format'),
        };
    }

    /** @return list<list<string|int|float|null>> */
    public function rows(PayoutStatement $s): array
    {
        // Exact decimal strings (same as the core CSV exporter); never floats for money.
        $fmt = fn (int $m): string => ($m < 0 ? '-' : '').intdiv(abs($m), 100).'.'.str_pad((string) (abs($m) % 100), 2, '0', STR_PAD_LEFT);
        $rows = [['statement_id', 'merchant_account', 'period_start', 'period_end', 'section', 'line', 'amount', 'currency']];
        $base = [$s->id, $s->mid_label, $s->period_start->toDateString(), $s->period_end->toDateString()];
        $rows[] = array_merge($base, ['activity', 'Gross sales ('.$s->input['approvedCount'].' approved)', $fmt($s->gross_minor), $s->currency]);
        foreach ($s->lines as $l) {
            $rows[] = array_merge($base, [$l['section'], $l['label'], $fmt((int) $l['amount_minor']), $s->currency]);
        }
        $rows[] = array_merge($base, ['total', 'Net payout', $fmt($s->net_minor), $s->currency]);

        return $rows;
    }

    private function pdf(PayoutStatement $s): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setChroot(resource_path('views'));
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('statements.pdf', ['s' => $s])->render());
        $dompdf->setPaper('letter');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
