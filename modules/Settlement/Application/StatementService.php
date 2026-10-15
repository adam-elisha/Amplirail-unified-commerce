<?php

declare(strict_types=1);

namespace Modules\Settlement\Application;

use AmpliRail\Money\Money;
use AmpliRail\Payouts\PayoutStatementCalculator;
use AmpliRail\Payouts\StatementExporter;
use AmpliRail\Payouts\StatementInput;
use App\Support\Actor;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Approvals\Contracts\ApprovalFailed;
use Modules\Approvals\Contracts\ApprovalHandler;
use Modules\Approvals\Contracts\ApprovalRequests;
use Modules\Approvals\Contracts\ApprovalView;
use Modules\Audit\Contracts\AuditTrail;
use Modules\Disputes\Contracts\DisputeReports;
use Modules\Events\Contracts\Outbox;
use Modules\Ledger\Contracts\Ledger;
use Modules\Onboarding\Contracts\MerchantDirectory;
use Modules\Payments\Contracts\PaymentReports;
use Modules\Routing\Contracts\MerchantAccounts;
use Modules\Settlement\Infrastructure\FeeAgreement;
use Modules\Settlement\Infrastructure\FeeSchedule;
use Modules\Settlement\Infrastructure\PayoutStatement;

/**
 * Payout statements (master document 9.9, AR-146 to AR-150). Every number comes from transactions, refunds and the
 * merchant's signed fee schedule version; the tested core PayoutStatementCalculator does the math (half-up per line,
 * totals are the sum of lines). Issuing needs a second person (Approvals: statement_issue) and posts fee and reserve
 * journals to the ledger.
 */
final class StatementService implements ApprovalHandler
{
    public function __construct(
        private readonly MerchantDirectory $merchants,
        private readonly MerchantAccounts $accounts,
        private readonly PaymentReports $payments,
        private readonly ApprovalRequests $approvals,
        private readonly Ledger $ledger,
        private readonly Outbox $outbox,
        private readonly AuditTrail $audit,
        private readonly DisputeReports $disputes,
    ) {}

    /**
     * Generates draft statements for every merchant with a signed fee schedule, for each of its MIDs.
     *
     * @return array{created: list<string>, skipped: list<array{merchant_id:string, reason:string}>}
     */
    public function generate(CarbonImmutable $start, CarbonImmutable $end, string $environment = 'live'): array
    {
        $created = [];
        $skipped = [];
        foreach ($this->merchants->merchantsWithFeeSchedule() as $merchantId) {
            $profile = $this->merchants->profile($merchantId);
            if ($profile === null || $profile->feeScheduleId === null) {
                continue;
            }
            $schedule = FeeSchedule::query()->whereKey($profile->feeScheduleId)->first();
            if ($schedule === null) {
                $skipped[] = ['merchant_id' => $merchantId, 'reason' => 'fee_schedule_missing'];

                continue;
            }
            $signed = FeeAgreement::query()->where('merchant_id', $merchantId)->where('fee_schedule_id', $schedule->id)
                ->where('status', 'signed')->where('signed_at', '<=', $end->endOfDay())->exists();
            if (! $signed) {
                $skipped[] = ['merchant_id' => $merchantId, 'reason' => 'agreement_not_signed'];

                continue;
            }
            foreach ($this->accounts->forMerchant($merchantId, $environment) as $account) {
                if ($account->currency !== $schedule->currency) {
                    $skipped[] = ['merchant_id' => $merchantId, 'reason' => "currency_mismatch:{$account->id}"];

                    continue;
                }
                $existing = PayoutStatement::query()->where('merchant_account_id', $account->id)
                    ->whereDate('period_start', $start->toDateString())->whereDate('period_end', $end->toDateString())->first();
                if ($existing !== null) {
                    continue;
                }
                $created[] = $this->draft($profile->id, $profile->legalName, $profile->payoutMethod ?? 'ach', $account->id, $account->label, $schedule, $start, $end)->id;
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    public function draft(string $merchantId, string $merchantName, string $payoutMethod, string $accountId, string $midLabel, FeeSchedule $schedule, CarbonImmutable $start, CarbonImmutable $end): PayoutStatement
    {
        $activity = $this->payments->statementActivity($accountId, $start->startOfDay(), $end->addDay()->startOfDay());
        $approvedCount = $activity['approved_count'];
        $gross = $activity['gross_minor'];
        $declined = $activity['declined_count'];
        $refundCount = $activity['refund_count'];
        $refundSum = $activity['refund_minor'];
        $firstOfMonth = ! PayoutStatement::query()->where('merchant_account_id', $accountId)
            ->where('period_start', '>=', $start->startOfMonth()->toDateString())->where('period_start', '<', $start->toDateString())->exists();

        // Chargebacks, retrievals and re-presentments from the Disputes module (cases opened or answered in the period).
        $dsp = $this->disputes->activity($accountId, $start->startOfDay(), $end->addDay()->startOfDay());
        $input = new StatementInput($merchantName, $midLabel, $start->toDateString(), $end->toDateString(), $approvedCount, $gross, $declined,
            $refundCount, $refundSum, $dsp['chargeback_count'], $dsp['chargeback_minor'], $dsp['retrieval_count'], $dsp['representment_count'],
            $firstOfMonth, $payoutMethod);
        $result = (new PayoutStatementCalculator)->calculate($input, $schedule->toCore());
        // Chargebacks won in the period come back to the merchant (the core calculator has no input for them).
        if ($dsp['reversal_minor'] > 0) {
            $won = Money::of($dsp['reversal_minor'], $schedule->currency);
            $result['lines'][] = ['section' => 'activity', 'label' => 'Chargebacks won (returned)', 'amount' => $won];
            $result['net'] = $result['net']->plus($won);
        }

        $statement = PayoutStatement::query()->create([
            'id' => Ids::new('ps'),
            'merchant_id' => $merchantId,
            'merchant_account_id' => $accountId,
            'mid_label' => $midLabel,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'currency' => $schedule->currency,
            'fee_schedule_id' => $schedule->id,
            'fee_schedule_version' => $schedule->version,
            'input' => get_object_vars($input),
            'lines' => array_map(fn ($l) => ['section' => $l['section'], 'label' => $l['label'], 'amount_minor' => $l['amount']->minor], $result['lines']),
            'gross_minor' => $result['gross']->minor,
            'fees_minor' => $result['fees']->minor,
            'reserve_minor' => $result['reserve']->minor,
            'net_minor' => $result['net']->minor,
            'cb_ratio_bps' => $result['cb_ratio_bps'],
            'status' => 'draft',
        ]);
        $this->audit->record('payout_statement.drafted', 'payout_statement', $statement->id, null, ['net_minor' => $statement->net_minor]);

        return $statement;
    }

    public function requestIssue(PayoutStatement $s, string $requestedBy): ApprovalView
    {
        if ($s->status !== 'draft') {
            throw new \DomainException('Only draft statements can be submitted for issue.');
        }

        return DB::transaction(function () use ($s, $requestedBy): ApprovalView {
            $approval = $this->approvals->request('statement_issue', 'payout_statement', $s->id, [
                'merchant_account' => $s->mid_label, 'period' => $s->period_start->toDateString().' to '.$s->period_end->toDateString(),
                'net_minor' => $s->net_minor, 'currency' => $s->currency,
            ], $requestedBy);
            $s->forceFill(['status' => 'pending_approval', 'approval_id' => $approval->id])->save();

            return $approval;
        });
    }

    public function type(): string
    {
        return 'statement_issue';
    }

    public function execute(ApprovalView $approval, Actor $approver): array
    {
        /** @var PayoutStatement|null $s */
        $s = PayoutStatement::query()->whereKey($approval->subjectId)->lockForUpdate()->first();
        if ($s === null || $s->status !== 'pending_approval') {
            throw new ApprovalFailed('Statement is not awaiting approval.');
        }
        $account = $this->accounts->find($s->merchant_account_id);
        $environment = $account->environment ?? 'live';
        $c = $s->currency;
        $lines = [];
        if ($s->fees_minor !== 0) {
            $lines[] = ['merchant_payable:'.$s->merchant_id, Money::of($s->fees_minor, $c)];
            $lines[] = ['fee_revenue', Money::of(-$s->fees_minor, $c)];
        }
        if ($s->reserve_minor !== 0) {
            $lines[] = ['merchant_payable:'.$s->merchant_id, Money::of($s->reserve_minor, $c)];
            $lines[] = ['reserve:'.$s->merchant_id, Money::of(-$s->reserve_minor, $c)];
        }
        if ($lines !== []) {
            $this->ledger->post('payout_statement', $s->id, $environment, $lines);
        }
        $s->forceFill(['status' => 'issued', 'issued_at' => now()])->save();
        $this->outbox->record('payout_statement.issued', 'payout_statement', $s->id, ['merchant_id' => $s->merchant_id, 'statement_id' => $s->id,
            'net_minor' => $s->net_minor, 'currency' => $c, 'period_end' => $s->period_end->toDateString()], $approver, $environment);

        return ['statement_id' => $s->id, 'net_minor' => $s->net_minor];
    }

    public function csv(PayoutStatement $s): string
    {
        [$input, $result] = $this->rebuild($s);

        return StatementExporter::csv($input, $result, $s->id);
    }

    /** @return array{0: StatementInput, 1: array{lines: list<array{section:string,label:string,amount:Money}>, gross:Money, net:Money}} */
    public function rebuild(PayoutStatement $s): array
    {
        $i = $s->input;
        $input = new StatementInput((string) $i['merchantName'], (string) $i['mid'], (string) $i['periodStart'], (string) $i['periodEnd'],
            (int) $i['approvedCount'], (int) $i['grossMinor'], (int) $i['declinedCount'], (int) $i['refundCount'], (int) $i['refundMinor'],
            (int) $i['chargebackCount'], (int) $i['chargebackMinor'], (int) $i['retrievalCount'], (int) $i['representmentCount'],
            (bool) $i['firstStatementOfMonth'], (string) $i['payoutMethod']);
        $result = [
            'lines' => array_map(fn ($l) => ['section' => $l['section'], 'label' => $l['label'], 'amount' => Money::of((int) $l['amount_minor'], $s->currency)], $s->lines),
            'gross' => Money::of($s->gross_minor, $s->currency),
            'net' => Money::of($s->net_minor, $s->currency),
        ];

        return [$input, $result];
    }

    public function recordAgreement(string $merchantId, string $feeScheduleId, string $signerName, string $documentHash, Actor $actor): FeeAgreement
    {
        $agreement = FeeAgreement::query()->create([
            'id' => Ids::new('fa'), 'merchant_id' => $merchantId, 'fee_schedule_id' => $feeScheduleId, 'signer_name' => $signerName,
            'signed_at' => now(), 'document_hash' => $documentHash, 'status' => 'signed', 'recorded_by' => $actor->id,
        ]);
        $this->audit->record('fee_agreement.signed', 'merchant', $merchantId, null, ['fee_schedule_id' => $feeScheduleId, 'agreement_id' => $agreement->id], $actor);

        return $agreement;
    }
}
