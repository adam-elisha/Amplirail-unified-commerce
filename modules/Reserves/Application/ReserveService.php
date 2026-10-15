<?php

declare(strict_types=1);

namespace Modules\Reserves\Application;

use AmpliRail\Money\Money;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;
use Modules\Approvals\Contracts\ApprovalFailed;
use Modules\Approvals\Contracts\ApprovalHandler;
use Modules\Approvals\Contracts\ApprovalRequests;
use Modules\Approvals\Contracts\ApprovalView;
use Modules\Events\Contracts\Outbox;
use Modules\Ledger\Contracts\Ledger;
use Modules\Reserves\Domain\ReserveBalance;

/**
 * Reserve release with dual control (Approvals: reserve_release, approver needs fees.approve and step-up).
 * Executing moves the amount from reserve:{merchant} back to merchant_payable:{merchant}, so it is paid out on the
 * next payout. The balance is re-checked at execution time, inside the approval's transaction.
 */
final class ReserveService implements ApprovalHandler
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly ApprovalRequests $approvals,
        private readonly Outbox $outbox,
    ) {}

    public function held(string $merchantId, string $currency, string $environment): int
    {
        return ReserveBalance::heldFromLedgerBalance($this->ledger->balance('reserve:'.$merchantId, $currency, $environment));
    }

    public function requestRelease(string $merchantId, string $merchantName, string $currency, string $environment, int $amountMinor, string $reason, string $requestedBy): ApprovalView
    {
        ReserveBalance::assertReleasable($this->held($merchantId, $currency, $environment), $amountMinor);
        if (mb_strlen(trim($reason)) < 5) {
            throw new \InvalidArgumentException('Please give a reason (at least 5 characters).');
        }

        return $this->approvals->request('reserve_release', 'merchant', $merchantId, [
            'merchant' => $merchantName,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'environment' => $environment,
        ], $requestedBy, mb_substr(trim($reason), 0, 500));
    }

    public function type(): string
    {
        return 'reserve_release';
    }

    public function execute(ApprovalView $approval, Actor $approver): array
    {
        $merchantId = $approval->subjectId;
        $p = $approval->payload;
        $currency = (string) ($p['currency'] ?? '');
        $environment = (string) ($p['environment'] ?? 'live');
        $amount = (int) ($p['amount_minor'] ?? 0);
        $held = $this->held($merchantId, $currency, $environment);
        try {
            ReserveBalance::assertReleasable($held, $amount);
        } catch (\InvalidArgumentException $e) {
            throw new ApprovalFailed($e->getMessage());
        }
        $money = Money::of($amount, $currency);
        $journal = DB::transaction(fn (): string => $this->ledger->post('reserve_release', $approval->id, $environment, [
            ['reserve:'.$merchantId, $money],
            ['merchant_payable:'.$merchantId, $money->negate()],
        ]));
        $this->outbox->record('reserve.released', 'merchant', $merchantId, [
            'merchant_id' => $merchantId, 'amount_minor' => $amount, 'currency' => $currency, 'approval_id' => $approval->id,
        ], $approver, $environment);

        return ['journal_id' => $journal, 'released_minor' => $amount, 'held_after_minor' => $held - $amount];
    }
}
