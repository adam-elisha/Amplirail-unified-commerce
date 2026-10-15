<?php

declare(strict_types=1);

namespace Modules\Ledger\Application;

use AmpliRail\Ledger\Ledger as CoreLedger;
use App\Support\Ids;
use Illuminate\Database\DatabaseManager;
use Modules\Ledger\Contracts\Ledger;
use Modules\Ledger\Infrastructure\LedgerPosting;

final class LedgerService implements Ledger
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function post(string $sourceType, string $sourceId, string $environment, array $lines): string
    {
        if ($this->db->connection()->transactionLevel() < 1) {
            throw new \LogicException('Ledger journals must be posted inside the transaction that caused them.');
        }
        $journalId = Ids::new('led');
        // The tested core ledger validates the journal (balanced per currency) before anything is written.
        (new CoreLedger)->post($journalId, $sourceType.':'.$sourceId, $lines);

        $now = now();
        foreach ($lines as [$account, $money]) {
            LedgerPosting::query()->create([
                'journal_id' => $journalId,
                'account' => $account,
                'amount_minor' => $money->minor,
                'currency' => $money->currency,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'environment' => $environment,
                'created_at' => $now,
            ]);
        }

        return $journalId;
    }

    public function balance(string $account, string $currency, string $environment): int
    {
        return (int) LedgerPosting::query()->where('account', $account)->where('currency', $currency)
            ->where('environment', $environment)->sum('amount_minor');
    }

    public function isBalanced(?string $environment = null): bool
    {
        $unbalanced = LedgerPosting::query()
            ->when($environment !== null, fn ($q) => $q->where('environment', $environment))
            ->groupBy('journal_id', 'currency')
            ->havingRaw('SUM(amount_minor) <> 0')
            ->select('journal_id')
            ->limit(1)
            ->get();

        return $unbalanced->isEmpty();
    }

    public function postingsForSource(string $sourceId): array
    {
        return array_values(LedgerPosting::query()->where('source_id', $sourceId)->orderBy('id')->get()
            ->map(fn (LedgerPosting $p) => [
                'journal_id' => $p->journal_id,
                'account' => $p->account,
                'amount_minor' => $p->amount_minor,
                'currency' => $p->currency,
                'created_at' => $p->created_at->toIso8601String(),
            ])->all());
    }
}
