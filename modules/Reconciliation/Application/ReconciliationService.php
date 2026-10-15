<?php

declare(strict_types=1);

namespace Modules\Reconciliation\Application;

use AmpliRail\Money\Money;
use App\Support\Actor;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Disputes\Contracts\DisputeReports;
use Modules\Events\Contracts\Outbox;
use Modules\Ledger\Contracts\Ledger;
use Modules\Payments\Contracts\PaymentReports;
use Modules\Reconciliation\Contracts\DepositAccount;
use Modules\Reconciliation\Domain\DepositMatch;
use Modules\Reconciliation\Infrastructure\ProcessorDeposit;

/**
 * Processor deposit reconciliation (master document 9.9, "expected vs received"). Each deposit the processor sends
 * for a MID is recorded once (unique by MID + reference), compared with what AmpliRail expected for the period, and
 * posted to the ledger: cash in the settlement bank account, processor receivable reduced by the same amount.
 */
final class ReconciliationService
{
    public function __construct(
        private readonly PaymentReports $payments,
        private readonly DisputeReports $disputes,
        private readonly Ledger $ledger,
        private readonly Outbox $outbox,
    ) {}

    /** @return array{gross_minor:int, refund_minor:int, chargeback_minor:int, reversal_minor:int, expected_minor:int} */
    public function expected(string $merchantAccountId, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $from = $periodStart->startOfDay();
        $to = $periodEnd->addDay()->startOfDay();
        $pay = $this->payments->statementActivity($merchantAccountId, $from, $to);
        $dsp = $this->disputes->activity($merchantAccountId, $from, $to);

        return [
            'gross_minor' => $pay['gross_minor'],
            'refund_minor' => $pay['refund_minor'],
            'chargeback_minor' => $dsp['chargeback_minor'],
            'reversal_minor' => $dsp['reversal_minor'],
            'expected_minor' => DepositMatch::expected($pay['gross_minor'], $pay['refund_minor'], $dsp['chargeback_minor'], $dsp['reversal_minor']),
        ];
    }

    public function record(
        DepositAccount $account,
        CarbonImmutable $depositDate,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        int $receivedMinor,
        string $reference,
        string $source,
        Actor $actor,
    ): ProcessorDeposit {
        $reference = trim($reference);
        if ($reference === '') {
            throw new \InvalidArgumentException('The deposit reference is required.');
        }
        if ($periodEnd->lessThan($periodStart)) {
            throw new \InvalidArgumentException('The period end must be on or after the period start.');
        }
        if ($receivedMinor < 0) {
            throw new \InvalidArgumentException('A negative deposit is a debit from the processor; record it as an adjustment with Finance.');
        }
        $exp = $this->expected($account->id, $periodStart, $periodEnd);
        $match = DepositMatch::compare($exp['expected_minor'], $receivedMinor);

        return DB::transaction(function () use ($account, $depositDate, $periodStart, $periodEnd, $receivedMinor, $reference, $source, $actor, $match): ProcessorDeposit {
            if (ProcessorDeposit::query()->where('merchant_account_id', $account->id)->where('reference', $reference)->exists()) {
                throw new \InvalidArgumentException("Deposit $reference is already recorded for {$account->label}.");
            }
            $d = ProcessorDeposit::query()->create([
                'id' => Ids::new('dep'),
                'merchant_id' => $account->merchantId,
                'merchant_account_id' => $account->id,
                'mid_label' => $account->label,
                'environment' => $account->environment,
                'currency' => $account->currency,
                'deposit_date' => $depositDate->toDateString(),
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'expected_minor' => $match->expectedMinor,
                'received_minor' => $match->receivedMinor,
                'difference_minor' => $match->differenceMinor,
                'reference' => mb_substr($reference, 0, 120),
                'source' => $source,
                'status' => $match->status,
                'recorded_by' => $actor->id,
            ]);
            if ($receivedMinor > 0) {
                $amount = Money::of($receivedMinor, $account->currency);
                $this->ledger->post('processor_deposit', $d->id, $account->environment, [
                    ['settlement_bank:'.$account->currency, $amount],
                    ['processor_receivable:'.$account->id, $amount->negate()],
                ]);
            }
            $this->outbox->record('reconciliation.deposit_'.$match->status, 'processor_deposit', $d->id, [
                'merchant_id' => $account->merchantId, 'merchant_account_id' => $account->id, 'currency' => $account->currency,
                'expected_minor' => $match->expectedMinor, 'received_minor' => $match->receivedMinor, 'difference_minor' => $match->differenceMinor,
            ], $actor, $account->environment);

            return $d;
        });
    }

    /** Finance explains an exception (processor fee, reserve, timing, adjustment). The ledger is not changed here. */
    public function resolve(string $depositId, string $note, Actor $actor): ProcessorDeposit
    {
        $note = trim($note);
        if (mb_strlen($note) < 5) {
            throw new \InvalidArgumentException('Please explain the difference (at least 5 characters).');
        }

        return DB::transaction(function () use ($depositId, $note, $actor): ProcessorDeposit {
            /** @var ProcessorDeposit $d */
            $d = ProcessorDeposit::query()->whereKey($depositId)->lockForUpdate()->firstOrFail();
            if ($d->status !== 'exception') {
                throw new \DomainException('Only exceptions can be resolved.');
            }
            $d->forceFill(['status' => 'resolved', 'resolution_note' => mb_substr($note, 0, 2000), 'resolved_by' => $actor->id, 'resolved_at' => now()])->save();
            $this->outbox->record('reconciliation.exception_resolved', 'processor_deposit', $d->id, [
                'merchant_id' => $d->merchant_id, 'difference_minor' => $d->difference_minor, 'currency' => $d->currency,
            ], $actor, $d->environment);

            return $d;
        });
    }
}
