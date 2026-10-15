<?php

declare(strict_types=1);

namespace Modules\Onboarding\Application;

use App\Support\Actor;
use App\Support\ApiException;
use App\Support\FeatureFlags;
use Connectors\Secrets\SecretsResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Approvals\Contracts\ApprovalFailed;
use Modules\Approvals\Contracts\ApprovalRequests;
use Modules\Approvals\Contracts\ApprovalView;
use Modules\Audit\Contracts\AuditTrail;
use Modules\Events\Contracts\Outbox;
use Modules\Identity\Contracts\AccessControl;
use Modules\Identity\Contracts\StepUpVerifier;
use Modules\Onboarding\Infrastructure\GoLiveEvaluation;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Routing\Contracts\MerchantAccounts;
use Modules\Webhooks\Contracts\WebhookStatus;
use Throwable;

/**
 * AR-172 automated go-live gate (V3.7.1 controlling rules):
 *  - every check is evaluated live from source data; a check that cannot be evaluated FAILS (fail closed);
 *  - all green opens a go_live Approvals item; a DIFFERENT authorized human approves with step-up and a note;
 *  - if the go_live_gate flag is off or unreadable, nobody can activate (no manual bypass exists);
 *  - emergency override = Platform Owner request with step-up and mandatory reason + second authorized human approval,
 *    recorded as an immutable audit event.
 */
final class GoLiveGate
{
    public const CHECKS = [
        'underwriting' => 'Underwriting decision recorded by an authorized user',
        'processor_approval' => 'Processor approval recorded',
        'production_mid' => 'Production MID/TID recorded',
        'credentials' => 'Credentials stored through the secrets process',
        'test_sale' => 'Test sale passed',
        'refund_void' => 'Refund and void path validated',
        'webhooks' => 'Webhook delivery validated',
        'settlement' => 'Settlement and funding configuration verified',
        'risk_controls' => 'Risk limits, reserve and descriptor confirmed',
        'pci' => 'PCI status recorded and current',
    ];

    public function __construct(
        private readonly FeatureFlags $flags,
        private readonly ApprovalRequests $approvals,
        private readonly MerchantAccounts $accounts,
        private readonly SecretsResolver $secrets,
        private readonly WebhookStatus $webhooks,
        private readonly RiskReview $risk,
        private readonly ValidationRunner $validation,
        private readonly AuditTrail $audit,
        private readonly Outbox $outbox,
        private readonly AccessControl $access,
        private readonly StepUpVerifier $stepUp,
    ) {}

    /** @return array{passed:bool, checks:list<array{code:string,label:string,passed:bool,evaluated:bool,evidence:array<string,mixed>}>} */
    public function evaluate(Merchant $m, Actor $actor): array
    {
        $checks = [];
        foreach (self::CHECKS as $code => $label) {
            try {
                [$passed, $evidence] = $this->check($code, $m);
                $checks[] = ['code' => $code, 'label' => $label, 'passed' => $passed, 'evaluated' => true, 'evidence' => $evidence];
            } catch (Throwable $e) {
                Log::warning('go_live.check_unavailable', ['merchant_id' => $m->id, 'check' => $code, 'error' => get_class($e)]);
                $checks[] = ['code' => $code, 'label' => $label, 'passed' => false, 'evaluated' => false, 'evidence' => ['error' => 'could_not_evaluate']];
            }
        }
        $passed = ! in_array(false, array_column($checks, 'passed'), true);
        GoLiveEvaluation::query()->create(['merchant_id' => $m->id, 'actor_id' => $actor->id, 'passed' => $passed, 'checks' => $checks, 'created_at' => now()]);
        $this->audit->record('go_live.evaluated', 'merchant', $m->id, null, ['passed' => $passed, 'failing' => array_values(array_column(array_filter($checks, fn ($c) => ! $c['passed']), 'code'))], $actor);

        return ['passed' => $passed, 'checks' => $checks];
    }

    /** @return array{approval:?ApprovalView, evaluation:array{passed:bool, checks:list<array<string,mixed>>}} */
    public function request(Merchant $m, string $requesterUserId, Actor $actor): array
    {
        $this->assertGateAvailable();
        if ($m->state !== 'validation') {
            throw ApiException::invalid('invalid_state', 'Go-live can be requested only after validation passed (state validation).');
        }

        return DB::transaction(function () use ($m, $requesterUserId, $actor): array {
            $evaluation = $this->evaluate($m, $actor);
            if (! $evaluation['passed']) {
                return ['approval' => null, 'evaluation' => $evaluation];
            }
            $approval = $this->approvals->request('go_live', 'merchant', $m->id, ['merchant' => $m->displayName(), 'public_id' => $m->public_id,
                'checks' => $evaluation['checks']], $requesterUserId);
            $this->outbox->record('merchant.go_live_requested', 'merchant', $m->id, ['merchant_id' => $m->id, 'approval_id' => $approval->id], $actor, 'platform', $m->org_path);

            return ['approval' => $approval, 'evaluation' => $evaluation];
        });
    }

    public function requestOverride(Merchant $m, string $requesterUserId, string $stepUpCode, string $reason, Actor $actor): ApprovalView
    {
        if (! $this->access->isPlatformOwner($requesterUserId, $m->tenant_id)) {
            $this->audit->record('go_live.override_denied', 'merchant', $m->id, null, ['user' => $requesterUserId, 'why' => 'not_platform_owner'], $actor);
            throw ApiException::forbidden('Only the Platform Owner can request an emergency go-live override.');
        }
        if (mb_strlen(trim($reason)) < 20) {
            throw ApiException::invalid('reason_required', 'An emergency override needs a detailed reason (20 characters or more).');
        }
        if (! $this->stepUp->verify($requesterUserId, $stepUpCode)) {
            throw ApiException::forbidden('Step-up verification failed.');
        }
        if ($m->state !== 'validation') {
            throw ApiException::invalid('invalid_state', 'Override applies to merchants in validation.');
        }

        return DB::transaction(function () use ($m, $requesterUserId, $reason, $actor): ApprovalView {
            $evaluation = $this->evaluate($m, $actor);
            $approval = $this->approvals->request('go_live_override', 'merchant', $m->id, ['merchant' => $m->displayName(),
                'public_id' => $m->public_id, 'checks' => $evaluation['checks'], 'emergency' => true], $requesterUserId, $reason);
            $this->audit->record('go_live.emergency_override_requested', 'merchant', $m->id, null, ['approval_id' => $approval->id, 'reason' => $reason], $actor);

            return $approval;
        });
    }

    /**
     * Called by the approval handlers inside the approval transaction.
     *
     * @param  callable(Merchant): array<string,mixed>  $afterLive
     * @return array<string,mixed>
     */
    public function activate(ApprovalView $approval, Actor $approver, bool $override, MerchantLifecycle $lifecycle, callable $afterLive): array
    {
        /** @var Merchant $m */
        $m = Merchant::query()->whereKey($approval->subjectId)->lockForUpdate()->firstOrFail();
        if (! $override) {
            if (! $this->flags->enabled('go_live_gate')) {
                throw new ApprovalFailed('Go-live gate is disabled or unavailable; the merchant stays in test mode.');
            }
            $evaluation = $this->evaluate($m, $approver);
            if (! $evaluation['passed']) {
                throw new ApprovalFailed('Go-live checks no longer pass; the merchant stays in test mode.', ['checks' => $evaluation['checks']]);
            }
        } else {
            $this->audit->record('go_live.emergency_override', 'merchant', $m->id, null,
                ['approval_id' => $approval->id, 'requested_by' => $approval->requestedBy, 'approved_by' => $approver->id, 'reason' => $approval->reason], $approver);
        }
        $lifecycle->apply($m, 'merchant.live', ['override' => $override], $approval->reason, $approval->id, $approver);
        $activated = $this->accounts->activateLiveAccounts($m->id);
        $extra = $afterLive($m);

        return ['merchant_id' => $m->id, 'state' => 'active', 'live_accounts_activated' => $activated, 'override' => $override] + $extra;
    }

    private function assertGateAvailable(): void
    {
        if (! $this->flags->enabled('go_live_gate')) {
            throw new ApiException(503, 'api_error', 'go_live_gate_unavailable', 'The go-live gate is disabled or unavailable. The merchant stays in test mode.');
        }
    }

    /** @return array{0:bool, 1:array<string,mixed>} */
    private function check(string $code, Merchant $m): array
    {
        switch ($code) {
            case 'underwriting':
                $case = $this->risk->latest($m);

                return [$m->risk_approved_at !== null && $case !== null && $case->decision === 'approve' && $case->decided_by !== null,
                    ['risk_case_id' => $case?->id, 'decided_by' => $case?->decided_by]];
            case 'processor_approval':
                return [$m->processor_approved_at !== null, ['route' => $m->processor_route, 'reference' => $m->processor_reference]];
            case 'production_mid':
                $live = $this->accounts->forMerchant($m->id, 'live');
                $withMid = array_values(array_filter($live, fn ($a) => $a->providerMid !== null));

                return [$withMid !== [], ['live_accounts' => array_map(fn ($a) => $a->id, $withMid)]];
            case 'credentials':
                $live = $this->accounts->forMerchant($m->id, 'live');
                if ($live === []) {
                    return [false, ['reason' => 'no live accounts']];
                }
                foreach ($live as $a) {
                    if ($a->credentialRef === null || ! $this->secrets->exists($a->credentialRef)) {
                        return [false, ['account' => $a->id, 'reason' => 'credential reference missing or unreadable']];
                    }
                }

                return [true, ['accounts' => count($live)]];
            case 'test_sale':
                $run = $this->validation->latestPassed($m);

                return [$run !== null && ($run->checks['test_sale']['passed'] ?? false) === true, ['validation_run_id' => $run?->id]];
            case 'refund_void':
                $run = $this->validation->latestPassed($m);

                return [$run !== null && ($run->checks['test_refund']['passed'] ?? false) && ($run->checks['test_void']['passed'] ?? false),
                    ['validation_run_id' => $run?->id]];
            case 'webhooks':
                return [$this->webhooks->hasSuccessfulDelivery($m->id), []];
            case 'settlement':
                $ok = $m->bank_token_ref !== null && $m->settlement_verified_at !== null && $m->funding_schedule !== null
                    && $m->fee_schedule_id !== null && $m->payout_method !== null;

                return [$ok, ['fee_schedule_id' => $m->fee_schedule_id, 'payout_method' => $m->payout_method, 'funding_schedule' => $m->funding_schedule,
                    'settlement_verified_at' => $m->settlement_verified_at?->toIso8601String()]];
            case 'risk_controls':
                $rp = $m->risk_profile ?? [];
                $ok = isset($rp['max_ticket_minor'], $rp['reserve_bps']) && $m->descriptor !== null && $m->descriptor !== '';

                return [$ok, ['max_ticket_minor' => $rp['max_ticket_minor'] ?? null, 'reserve_bps' => $rp['reserve_bps'] ?? null, 'descriptor' => $m->descriptor]];
            case 'pci':
                $ok = in_array($m->pci_status, ['compliant', 'validated'], true) && $m->pci_expires_at !== null && $m->pci_expires_at->isFuture();

                return [$ok, ['pci_status' => $m->pci_status, 'pci_expires_at' => $m->pci_expires_at?->toDateString()]];
        }

        throw new \LogicException("Unknown check $code");
    }
}
