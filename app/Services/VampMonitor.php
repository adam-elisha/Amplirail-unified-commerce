<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Ids;
use Carbon\CarbonImmutable;
use Modules\Disputes\Infrastructure\Dispute;
use Modules\Monitoring\Domain\VampCalculator;
use Modules\Monitoring\Infrastructure\FraudReport;
use Modules\Payments\Infrastructure\Transaction;

/**
 * Builds the monthly VAMP view per merchant and for the whole portfolio, and imports TC40 / SAFE files.
 * Read-only towards payments: it never reroutes traffic. Moving a merchant's sales to other MIDs to stay under a
 * network monitoring threshold breaks card network rules (and AGENTS.md rule 21); the response to a breach is a
 * remediation plan, tighter screening, or suspension through Approvals.
 */
final class VampMonitor
{
    /** Entry modes that are card present; everything else counts as card not present. */
    private const CARD_PRESENT = ['chip', 'contactless', 'swipe', 'emv', 'card_present'];

    /** @return array{rows: list<array<string,mixed>>, portfolio: array<string,mixed>} */
    public function month(CarbonImmutable $month, string $environment, string $region): array
    {
        $from = $month->startOfMonth();
        $to = $from->addMonth();

        $cnp = Transaction::query()->where('environment', $environment)->whereRaw('lower(coalesce(card_brand, \'\')) = ?', ['visa'])
            ->whereIn('state', ['captured', 'partially_refunded', 'refunded'])
            ->where(fn ($q) => $q->whereNull('entry_mode')->orWhereNotIn('entry_mode', self::CARD_PRESENT))
            ->where('created_at', '>=', $from)->where('created_at', '<', $to);
        $settled = (clone $cnp)->selectRaw('merchant_id, count(*) as n')->groupBy('merchant_id')->pluck('n', 'merchant_id');

        $fraud = FraudReport::query()->where('environment', $environment)->where('network', 'visa')
            ->where('report_date', '>=', $from->toDateString())->where('report_date', '<', $to->toDateString())->get(['merchant_id', 'transaction_id']);
        $disputes = Dispute::query()->where('environment', $environment)->where('stage', '!=', 'inquiry')
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->whereIn('transaction_id', Transaction::query()->whereRaw('lower(coalesce(card_brand, \'\')) = ?', ['visa'])->select('id'))
            ->get(['merchant_id', 'transaction_id']);

        $merchants = collect($settled->keys())->merge($fraud->pluck('merchant_id'))->merge($disputes->pluck('merchant_id'))->unique()->values();
        $rows = [];
        $allFraud = [];
        $allDisputes = [];
        $allUnmatched = 0;
        foreach ($merchants as $mid) {
            $f = $fraud->where('merchant_id', $mid);
            $fIds = array_values(array_map('strval', $f->pluck('transaction_id')->filter()->all()));
            $unmatched = $f->whereNull('transaction_id')->count();
            $dIds = array_values(array_map('strval', $disputes->where('merchant_id', $mid)->pluck('transaction_id')->all()));
            $settledCount = (int) ($settled[$mid] ?? 0);
            $r = VampCalculator::ratio($fIds, $dIds, $unmatched, $settledCount);
            $rows[] = [
                'merchant_id' => $mid,
                'settled_cnp' => $settledCount,
                'tc40' => $f->count(),
                'tc15' => count($dIds),
                'count' => $r['count'],
                'ratio_bps' => $r['ratio_bps'],
                'status' => VampCalculator::merchantStatus($r['ratio_bps'], $r['count'], $region),
            ];
            $allFraud = array_merge($allFraud, $fIds);
            $allDisputes = array_merge($allDisputes, $dIds);
            $allUnmatched += $unmatched;
        }
        usort($rows, fn ($a, $b) => $b['ratio_bps'] <=> $a['ratio_bps']);
        $p = VampCalculator::ratio($allFraud, $allDisputes, $allUnmatched, (int) $settled->sum());

        return ['rows' => $rows, 'portfolio' => [
            'settled_cnp' => (int) $settled->sum(), 'count' => $p['count'], 'ratio_bps' => $p['ratio_bps'],
            'status' => VampCalculator::portfolioStatus($p['ratio_bps']),
        ]];
    }

    /**
     * CSV columns (header row): network, report_type, network_ref, report_date, transaction_id, amount, fraud_type.
     * transaction_id may be the AmpliRail ID or the processor's transaction reference.
     *
     * @param  list<string>  $lines
     * @return array{imported:int, errors:list<string>}
     */
    public function import(array $lines, string $recordedBy): array
    {
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), str_getcsv((string) array_shift($lines)));
        $need = ['network', 'report_type', 'network_ref', 'report_date', 'transaction_id', 'amount'];
        if (array_diff($need, $header) !== []) {
            return ['imported' => 0, 'errors' => ['The CSV needs these columns: '.implode(', ', $need).'.']];
        }
        $ok = 0;
        $errors = [];
        foreach (array_slice($lines, 0, 2000) as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = array_combine($header, array_pad(str_getcsv($line), count($header), ''));
            $network = strtolower(trim((string) $row['network']));
            $type = strtolower(trim((string) $row['report_type']));
            $ref = trim((string) $row['network_ref']);
            $txnRef = trim((string) $row['transaction_id']);
            $t = $txnRef === '' ? null : Transaction::query()->where(fn ($q) => $q->whereKey($txnRef)->orWhere('provider_txn_id', $txnRef))->first();
            $minor = \App\Support\Amount::toMinor((string) $row['amount']);
            $line = 'Row '.($i + 2).': ';
            if (! in_array($network, ['visa', 'mastercard'], true) || ! in_array($type, ['tc40', 'safe'], true) || $ref === '') {
                $errors[] = $line.'network must be visa or mastercard, report_type tc40 or safe, network_ref required';

                continue;
            }
            if ($t === null || $minor === null) {
                $errors[] = $line.($t === null ? "transaction $txnRef not found" : 'bad amount');

                continue;
            }
            if (FraudReport::query()->where('network', $network)->where('network_ref', $ref)->exists()) {
                $errors[] = $line."report $ref already imported";

                continue;
            }
            try {
                FraudReport::query()->create([
                    'id' => Ids::new('frd'), 'merchant_id' => $t->merchant_id, 'merchant_account_id' => $t->merchant_account_id,
                    'transaction_id' => $t->id, 'environment' => $t->environment, 'network' => $network, 'report_type' => $type,
                    'fraud_type' => mb_substr(trim((string) ($row['fraud_type'] ?? '')), 0, 40) ?: null, 'network_ref' => mb_substr($ref, 0, 80),
                    'report_date' => CarbonImmutable::parse((string) $row['report_date'])->toDateString(), 'amount_minor' => $minor,
                    'currency' => $t->currency, 'source' => 'csv', 'recorded_by' => $recordedBy,
                ]);
                $ok++;
            } catch (\Throwable) {
                $errors[] = $line.'could not be read (check the date)';
            }
        }

        return ['imported' => $ok, 'errors' => $errors];
    }
}
