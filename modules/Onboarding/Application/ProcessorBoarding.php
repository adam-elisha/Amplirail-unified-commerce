<?php

declare(strict_types=1);

namespace Modules\Onboarding\Application;

use App\Support\Actor;
use App\Support\ApiException;
use App\Support\FeatureFlags;
use Connectors\Boarding\MerchantBoarding;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Contracts\AuditTrail;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Onboarding\Infrastructure\MerchantDocument;
use Modules\Routing\Contracts\AccountView;
use Modules\Routing\Contracts\Eligibility;
use Modules\Routing\Contracts\MerchantAccounts;

/**
 * Days 1-3: processor eligibility (AR-170), submission, approval, production MID/TID and credential reference.
 * Processor answers are recorded by operations until boarding APIs are integrated (MerchantBoarding connector).
 */
final class ProcessorBoarding
{
    public function __construct(
        private readonly Eligibility $eligibility,
        private readonly MerchantBoarding $boarding,
        private readonly MerchantAccounts $accounts,
        private readonly MerchantLifecycle $lifecycle,
        private readonly RiskReview $risk,
        private readonly FeatureFlags $flags,
        private readonly AuditTrail $audit,
    ) {}

    /** @return array<string,mixed>|null */
    public function evaluateEligibility(Merchant $m): ?array
    {
        if (! $this->flags->enabled('eligibility_engine')) {
            return null;
        }

        return $this->eligibility->evaluate($m->id, $this->profile($m));
    }

    public function submit(Merchant $m, string $routeCode, Actor $actor, ?string $overrideReason = null): void
    {
        if ($m->state !== 'approved') {
            throw ApiException::invalid('invalid_state', 'Only approved merchants can be submitted to a processor.');
        }
        $route = null;
        if ($this->flags->enabled('eligibility_engine')) {
            $result = $this->evaluateEligibility($m) ?? ['eligible' => [], 'ineligible' => []];
            foreach ((array) $result['eligible'] as $candidate) {
                if (is_array($candidate) && ($candidate['code'] ?? null) === $routeCode) {
                    $route = $candidate;
                }
            }
            if ($route === null) {
                $failed = 'unknown route';
                foreach ((array) $result['ineligible'] as $candidate) {
                    if (is_array($candidate) && ($candidate['code'] ?? null) === $routeCode) {
                        $failed = $candidate['failed_rules'] ?? $failed;
                    }
                }
                throw ApiException::invalid('route_not_eligible', 'This route cannot take the merchant.', ['failed_rules' => $failed]);
            }
        }
        // RoboRiskAnalysis pre-submission gate (master document 10.2): complete, or an authorized override with reason.
        $case = $this->risk->latest($m);
        if (($case === null || ! $case->gate_complete) && ($overrideReason === null || mb_strlen($overrideReason) < 10)) {
            throw ApiException::invalid('risk_gate_incomplete', 'RoboRiskAnalysis has not completed the pre-submission gate. Record an override with a reason to proceed.');
        }

        $result = $this->boarding->submitApplication($routeCode, [
            'merchant_id' => $m->id, 'public_id' => $m->public_id, 'legal_name' => $m->legal_name, 'mcc' => $m->mcc,
            'country' => $m->country, 'currency' => $m->currency, 'channels' => $m->channels,
            'documents' => MerchantDocument::query()->where('merchant_id', $m->id)->where('status', 'active')->pluck('id')->all(),
        ]);
        if ($result->status !== 'submitted') {
            throw ApiException::invalid('boarding_failed', $result->message);
        }
        DB::transaction(function () use ($m, $routeCode, $route, $result, $actor, $overrideReason): void {
            $m->forceFill(['processor_route' => $routeCode, 'processor_reference' => $result->reference])->save();
            if ($overrideReason !== null) {
                $this->audit->record('risk.gate_override', 'merchant', $m->id, null, ['reason' => $overrideReason], $actor);
            }
            $this->lifecycle->apply($m->refresh(), 'processor.submitted', ['route' => $routeCode, 'connector' => $route['connector'] ?? null,
                'reference' => $result->reference], null, null, $actor);
        });
    }

    public function recordApproval(Merchant $m, string $note, Actor $actor): void
    {
        $this->lifecycle->apply($m, 'processor.approved', ['route' => $m->processor_route, 'note' => $note], $note, null, $actor);
    }

    public function recordMid(Merchant $m, string $connector, string $providerMid, ?string $providerTid, ?string $descriptor, Actor $actor): AccountView
    {
        if ($m->processor_approved_at === null) {
            throw ApiException::invalid('processor_not_approved', 'Record the processor approval before the production MID.');
        }
        if (! array_key_exists($connector, (array) config('connectors')) || $connector === 'simulator') {
            throw ApiException::invalid('invalid_connector', 'Production MIDs need a live connector.');
        }

        return DB::transaction(function () use ($m, $connector, $providerMid, $providerTid, $descriptor, $actor): AccountView {
            $account = $this->accounts->recordLiveAccount($m->id, $connector, $providerMid, $providerTid, $m->currency, $descriptor ?? $m->descriptor,
                (int) ($m->risk_profile['max_ticket_minor'] ?? $m->max_ticket_minor));
            $this->lifecycle->apply($m, 'mid.created', ['merchant_account_id' => $account->id, 'mid_label' => $account->label, 'connector' => $connector], null, null, $actor);

            return $account;
        });
    }

    /** credential_ref is the secrets-manager NAME the platform team created; the credential itself never passes through here. */
    public function recordCredentials(Merchant $m, string $accountId, string $credentialRef, Actor $actor): void
    {
        if ($m->mid_created_at === null) {
            throw ApiException::invalid('mid_missing', 'Record the production MID first.');
        }
        $account = $this->accounts->find($accountId);
        if ($account === null || $account->merchantId !== $m->id || $account->environment !== 'live') {
            throw ApiException::notFound('live merchant account');
        }
        DB::transaction(function () use ($m, $accountId, $credentialRef, $actor): void {
            $this->accounts->setCredentialRef($accountId, $credentialRef);
            $this->lifecycle->apply($m, 'credentials.created', ['merchant_account_id' => $accountId, 'stored_via' => 'secrets_manager'], null, null, $actor);
        });
    }

    /**
     * Go-live configuration (settlement, risk controls, descriptor, PCI). Locked once the merchant is live: later changes
     * need an approvals flow (follow-up ticket), never a direct edit.
     *
     * @param  array<string,mixed>  $input
     */
    public function configure(Merchant $m, array $input, Actor $actor): void
    {
        if (in_array($m->state, ['active', 'closed'], true)) {
            throw ApiException::forbidden('Live merchants change risk and settlement settings through Approvals.');
        }
        $before = $m->only(['descriptor', 'pci_status', 'pci_expires_at', 'fee_schedule_id', 'payout_method', 'funding_schedule', 'settlement_verified_at', 'risk_profile']);
        $changes = array_intersect_key($input, array_flip(['descriptor', 'pci_status', 'pci_expires_at', 'fee_schedule_id', 'payout_method', 'funding_schedule']));
        $risk = $m->risk_profile ?? [];
        foreach (['reserve_bps', 'max_ticket_minor', 'funding_delay_days', 'max_per_token_per_hour'] as $k) {
            if (isset($input[$k]) && $input[$k] !== '') {
                $risk[$k] = (int) $input[$k];
            }
        }
        $changes['risk_profile'] = $risk;
        if (! empty($input['settlement_verified'])) {
            $changes['settlement_verified_at'] = $m->settlement_verified_at ?? now();
        }
        $m->forceFill($changes)->save();
        $this->audit->record('merchant.go_live_config_changed', 'merchant', $m->id, $before, $m->only(array_keys($before)), $actor);
    }

    /** @return array{country:string, region:?string, mcc:string, channels:list<string>, recurring:bool, monthly_volume_minor:int, max_ticket_minor:int, chargeback_ratio_bps:?int, currency:string, reserve_bps:?int, documents:list<string>} */
    public function profile(Merchant $m): array
    {
        /** @var list<string> $docs */
        $docs = MerchantDocument::query()->where('merchant_id', $m->id)->where('status', 'active')->distinct()->pluck('document_type')->all();
        $reserve = $m->risk_profile['reserve_bps'] ?? $this->risk->latest($m)?->recommended_controls['reserve_bps'] ?? null;

        return [
            'country' => $m->country,
            'region' => $m->region,
            'mcc' => $m->mcc,
            'channels' => array_values($m->channels),
            'recurring' => $m->recurring,
            'monthly_volume_minor' => $m->monthly_volume_minor,
            'max_ticket_minor' => $m->max_ticket_minor,
            'chargeback_ratio_bps' => $m->chargeback_ratio_bps,
            'currency' => $m->currency,
            'reserve_bps' => $reserve === null ? null : (int) $reserve,
            'documents' => $docs,
        ];
    }
}
