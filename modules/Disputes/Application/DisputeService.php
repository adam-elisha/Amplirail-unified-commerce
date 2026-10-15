<?php

declare(strict_types=1);

namespace Modules\Disputes\Application;

use AmpliRail\Money\Money;
use App\Support\Actor;
use App\Support\Ids;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Disputes\Contracts\DisputedTransaction;
use Modules\Disputes\Domain\DisputeStateMachine;
use Modules\Disputes\Infrastructure\Dispute;
use Modules\Events\Contracts\Outbox;
use Modules\Ledger\Contracts\Ledger;

/**
 * Disputes and chargebacks (master document 9.6). Every change runs in one database transaction with its ledger
 * journal and its outbox event (AGENTS.md rules 5 and 7).
 *
 * Money: when a chargeback is opened the processor takes the amount back, so the merchant is owed that much less
 * (same journal shape as a refund). If the dispute is won the amount comes back and the journal is reversed.
 * Inquiries (retrieval requests) move no money. Chargeback fees come only from versioned fee rules on the payout
 * statement (rule 18), never from here.
 */
final class DisputeService
{
    public function __construct(private readonly Ledger $ledger, private readonly Outbox $outbox) {}

    public function open(
        DisputedTransaction $txn,
        int $amountMinor,
        string $stage,
        string $reasonCategory,
        ?string $reasonCode,
        string $networkCaseId,
        ?Carbon $dueAt,
        Actor $actor,
    ): Dispute {
        if (! array_key_exists($reasonCategory, DisputeStateMachine::CATEGORIES)) {
            throw new \InvalidArgumentException('Unknown dispute reason.');
        }
        if (! in_array($stage, DisputeStateMachine::STAGES, true)) {
            throw new \InvalidArgumentException('Unknown dispute stage.');
        }
        if ($amountMinor < 1 || $amountMinor > $txn->capturedMinor) {
            throw new \InvalidArgumentException('Dispute amount must be more than zero and no more than the captured amount.');
        }
        $networkCaseId = trim($networkCaseId);
        if ($networkCaseId === '') {
            throw new \InvalidArgumentException('The network case number is required.');
        }

        return DB::transaction(function () use ($txn, $amountMinor, $stage, $reasonCategory, $reasonCode, $networkCaseId, $dueAt, $actor): Dispute {
            if (Dispute::query()->where('transaction_id', $txn->id)->where('network_case_id', $networkCaseId)->exists()) {
                throw new \InvalidArgumentException('This case number is already recorded for this transaction.');
            }
            $d = Dispute::query()->create([
                'id' => Ids::new('dsp'),
                'merchant_id' => $txn->merchantId,
                'merchant_account_id' => $txn->merchantAccountId,
                'transaction_id' => $txn->id,
                'environment' => $txn->environment,
                'amount_minor' => $amountMinor,
                'currency' => $txn->currency,
                'stage' => $stage,
                'reason_category' => $reasonCategory,
                'reason_code' => $reasonCode !== null && trim($reasonCode) !== '' ? mb_substr(trim($reasonCode), 0, 20) : null,
                'network_case_id' => mb_substr($networkCaseId, 0, 80),
                'status' => 'needs_response',
                'due_at' => $dueAt,
                'opened_by' => $actor->id,
            ]);
            if (DisputeStateMachine::fundsHeld($stage, 'needs_response')) {
                $this->postDebit($d);
            }
            $this->outbox->record('dispute.opened', 'dispute', $d->id, $this->eventData($d), $actor, $d->environment);

            return $d;
        });
    }

    /**
     * Merchant (or staff on the merchant's behalf) submits the response and evidence.
     *
     * @param  list<array{type:string, description:string}>  $evidence
     */
    public function respond(string $disputeId, string $response, array $evidence, Actor $actor): Dispute
    {
        $response = trim($response);
        if (mb_strlen($response) < 20) {
            throw new \InvalidArgumentException('Please describe your response in at least 20 characters.');
        }

        return DB::transaction(function () use ($disputeId, $response, $evidence, $actor): Dispute {
            $d = $this->lock($disputeId);
            DisputeStateMachine::assert($d->status, 'under_review');
            if ($d->due_at !== null && $d->due_at->isPast()) {
                throw new \InvalidArgumentException('The response deadline has passed.');
            }
            $d->forceFill([
                'status' => 'under_review',
                'merchant_response' => mb_substr($response, 0, 10000),
                'evidence' => array_values(array_map(fn (array $e): array => [
                    'type' => mb_substr($e['type'], 0, 40),
                    'description' => mb_substr($e['description'], 0, 500),
                ], $evidence)),
                'responded_at' => now(),
            ])->save();
            $this->outbox->record('dispute.evidence_submitted', 'dispute', $d->id, $this->eventData($d) + ['evidence_items' => count($evidence)], $actor, $d->environment);

            return $d;
        });
    }

    /** Merchant accepts the chargeback: the funds stay with the cardholder and the case closes. */
    public function accept(string $disputeId, Actor $actor): Dispute
    {
        return DB::transaction(function () use ($disputeId, $actor): Dispute {
            $d = $this->lock($disputeId);
            DisputeStateMachine::assert($d->status, 'accepted');
            $d->forceFill(['status' => 'accepted', 'resolved_by' => $actor->id, 'resolved_at' => now()])->save();
            $this->outbox->record('dispute.accepted', 'dispute', $d->id, $this->eventData($d), $actor, $d->environment);

            return $d;
        });
    }

    /** Staff record the network outcome. Won reverses the debit; lost keeps it. */
    public function resolve(string $disputeId, string $outcome, ?string $note, Actor $actor): Dispute
    {
        if (! in_array($outcome, ['won', 'lost'], true)) {
            throw new \InvalidArgumentException('Outcome must be won or lost.');
        }

        return DB::transaction(function () use ($disputeId, $outcome, $note, $actor): Dispute {
            $d = $this->lock($disputeId);
            DisputeStateMachine::assert($d->status, $outcome);
            $wasHeld = DisputeStateMachine::fundsHeld($d->stage, $d->status);
            $d->forceFill([
                'status' => $outcome,
                'resolution_note' => $note !== null ? mb_substr(trim($note), 0, 2000) : null,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ])->save();
            if ($outcome === 'won' && $wasHeld) {
                $this->postReversal($d);
            }
            $this->outbox->record('dispute.'.$outcome, 'dispute', $d->id, $this->eventData($d), $actor, $d->environment);

            return $d;
        });
    }

    private function lock(string $id): Dispute
    {
        /** @var Dispute $d */
        $d = Dispute::query()->whereKey($id)->lockForUpdate()->firstOrFail();

        return $d;
    }

    private function postDebit(Dispute $d): void
    {
        if ($d->merchant_account_id === null) {
            return; // sandbox simulator payments without a MID never posted a sale journal
        }
        $amount = Money::of($d->amount_minor, $d->currency);
        $this->ledger->post('dispute', $d->id, $d->environment, [
            ['merchant_payable:'.$d->merchant_id, $amount],
            ['processor_receivable:'.$d->merchant_account_id, $amount->negate()],
        ]);
    }

    private function postReversal(Dispute $d): void
    {
        if ($d->merchant_account_id === null) {
            return;
        }
        $amount = Money::of($d->amount_minor, $d->currency);
        $this->ledger->post('dispute_reversal', $d->id, $d->environment, [
            ['merchant_payable:'.$d->merchant_id, $amount->negate()],
            ['processor_receivable:'.$d->merchant_account_id, $amount],
        ]);
    }

    /** @return array<string,mixed> */
    private function eventData(Dispute $d): array
    {
        return [
            'merchant_id' => $d->merchant_id,
            'transaction_id' => $d->transaction_id,
            'status' => $d->status,
            'stage' => $d->stage,
            'reason_category' => $d->reason_category,
            'amount_minor' => $d->amount_minor,
            'currency' => $d->currency,
        ];
    }
}
