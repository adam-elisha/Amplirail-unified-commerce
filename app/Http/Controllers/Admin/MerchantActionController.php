<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Onboarding\Application\DocumentChecklist;
use Modules\Onboarding\Application\GoLiveGate;
use Modules\Onboarding\Application\KybReview;
use Modules\Onboarding\Application\MerchantLifecycle;
use Modules\Onboarding\Application\ProcessorBoarding;
use Modules\Onboarding\Application\RiskReview;
use Modules\Onboarding\Application\ValidationRunner;
use Modules\Onboarding\Infrastructure\MerchantDocument;
use Modules\Settlement\Application\StatementService;

/** Merchant 360 actions. Each one delegates to the module service that owns the rule (state machine, gate, approvals). */
final class MerchantActionController extends AdminController
{
    public function upload(Request $request, string $merchant, DocumentChecklist $checklist): RedirectResponse
    {
        $request->validate(['document_type' => ['required', 'string', 'max:60'], 'file' => ['required', 'file']]);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $checklist->upload($this->merchant($merchant), $file, (string) $request->input('document_type'), $this->actor());

        return back()->with('status', 'Document uploaded and encrypted.');
    }

    public function removeDocument(string $merchant, string $document, DocumentChecklist $checklist): RedirectResponse
    {
        $doc = MerchantDocument::query()->whereKey($document)->where('merchant_id', $this->merchant($merchant)->id)->firstOrFail();
        $checklist->remove($doc, $this->actor());

        return back()->with('status', 'Document removed.');
    }

    /** Manual path when the checklist flag is off: operations confirm the set is complete (audited, reason required). */
    public function documentsComplete(Request $request, string $merchant, MerchantLifecycle $lifecycle): RedirectResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
        $lifecycle->apply($this->merchant($merchant), 'documents.complete', ['manual' => true], $reason, null, $this->actor());

        return back()->with('status', 'Documents marked complete.');
    }

    public function requestInfo(Request $request, string $merchant, DocumentChecklist $checklist): RedirectResponse
    {
        $d = $request->validate([
            'document_type' => ['required', 'regex:/^[a-z0-9_]{2,60}$/'], 'label' => ['required', 'string', 'max:120'],
            'reason' => ['required', 'string', 'max:500'], 'source' => ['required', 'in:processor,risk,operations'],
        ]);
        $checklist->requestMoreInfo($this->merchant($merchant), (string) $d['document_type'], (string) $d['label'], (string) $d['reason'], (string) $d['source'], $this->actor());

        return back()->with('status', 'Request recorded; the merchant sees it in their portal.');
    }

    public function runKyb(string $merchant, KybReview $kyb): RedirectResponse
    {
        $check = $kyb->run($this->merchant($merchant), $this->actor());

        return back()->with($check->status === 'passed' ? 'status' : 'error', 'KYB result: '.$check->status);
    }

    public function resolveKyb(Request $request, string $merchant, KybReview $kyb): RedirectResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
        $kyb->resolveManually($this->merchant($merchant), $reason, $this->actor());

        return back()->with('status', 'KYB resolved; merchant moved to underwriting.');
    }

    public function assessRisk(string $merchant, RiskReview $risk): RedirectResponse
    {
        $case = $risk->requestAssessment($this->merchant($merchant));

        return back()->with('status', "Risk assessment received: {$case->band} ({$case->score}).");
    }

    public function decideRisk(Request $request, string $merchant, RiskReview $risk, ProcessorBoarding $boarding): RedirectResponse
    {
        $d = $request->validate([
            'decision' => ['required', 'in:approve,decline,manual_review'], 'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'step_up_code' => ['required', 'string', 'max:8'], 'apply_controls' => ['sometimes', 'boolean'],
        ]);
        $m = $this->merchant($merchant);
        $risk->decide($m, (string) $d['decision'], (string) $d['reason'], $this->userId(), (string) $d['step_up_code'], (bool) ($d['apply_controls'] ?? false), $this->actor());
        if ($d['decision'] === 'approve') {
            $boarding->evaluateEligibility($m->refresh());
        }

        return back()->with('status', 'Underwriting decision recorded.');
    }

    public function evaluateEligibility(string $merchant, ProcessorBoarding $boarding): RedirectResponse
    {
        $result = $boarding->evaluateEligibility($this->merchant($merchant));

        return back()->with($result === null ? 'error' : 'status', $result === null ? 'The eligibility engine is off or no rule set is published.' : 'Eligibility evaluated.');
    }

    public function submitToProcessor(Request $request, string $merchant, ProcessorBoarding $boarding): RedirectResponse
    {
        $d = $request->validate(['route' => ['required', 'string', 'max:60'], 'override_reason' => ['nullable', 'string', 'max:500']]);
        $boarding->submit($this->merchant($merchant), (string) $d['route'], $this->actor(), $d['override_reason'] ?? null ?: null);

        return back()->with('status', 'Submitted to the processor route.');
    }

    public function processorApproved(Request $request, string $merchant, ProcessorBoarding $boarding): RedirectResponse
    {
        $note = (string) $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']])['note'];
        $boarding->recordApproval($this->merchant($merchant), $note, $this->actor());

        return back()->with('status', 'Processor approval recorded.');
    }

    public function recordMid(Request $request, string $merchant, ProcessorBoarding $boarding): RedirectResponse
    {
        $d = $request->validate([
            'connector' => ['required', 'string', 'max:40'], 'provider_mid' => ['required', 'regex:/^[A-Za-z0-9\-]{4,40}$/'],
            'provider_tid' => ['nullable', 'regex:/^[A-Za-z0-9\-]{1,40}$/'], 'descriptor' => ['nullable', 'string', 'max:25'],
        ]);
        $boarding->recordMid($this->merchant($merchant), (string) $d['connector'], (string) $d['provider_mid'], $d['provider_tid'] ?? null, $d['descriptor'] ?? null, $this->actor());

        return back()->with('status', 'Production MID recorded (inactive until go-live).');
    }

    public function recordCredentials(Request $request, string $merchant, ProcessorBoarding $boarding): RedirectResponse
    {
        $d = $request->validate(['merchant_account_id' => ['required', 'string'], 'credential_ref' => ['required', 'string', 'max:200']]);
        $boarding->recordCredentials($this->merchant($merchant), (string) $d['merchant_account_id'], (string) $d['credential_ref'], $this->actor());

        return back()->with('status', 'Credential reference recorded; merchant is in test mode.');
    }

    public function configure(Request $request, string $merchant, ProcessorBoarding $boarding): RedirectResponse
    {
        $d = $request->validate([
            'descriptor' => ['nullable', 'regex:/^[A-Za-z0-9 .\-*&]{3,25}$/'], 'pci_status' => ['nullable', 'in:compliant,validated,pending,not_compliant'],
            'pci_expires_at' => ['nullable', 'date'], 'fee_schedule_id' => ['nullable', 'string', 'max:40'], 'payout_method' => ['nullable', 'in:ach,wire_intl,usdt'],
            'funding_schedule' => ['nullable', 'in:daily,weekly,monthly'], 'settlement_verified' => ['sometimes', 'boolean'],
            'reserve_bps' => ['nullable', 'integer', 'min:0', 'max:5000'], 'max_ticket_minor' => ['nullable', 'integer', 'min:1'],
            'funding_delay_days' => ['nullable', 'integer', 'min:0', 'max:60'],
        ]);
        $boarding->configure($this->merchant($merchant), array_filter($d, fn ($v) => $v !== null), $this->actor());

        return back()->with('status', 'Go-live configuration saved.');
    }

    public function recordAgreement(Request $request, string $merchant, StatementService $statements): RedirectResponse
    {
        $d = $request->validate(['fee_schedule_id' => ['required', 'string'], 'signer_name' => ['required', 'string', 'max:120'],
            'document_hash' => ['required', 'regex:/^[a-f0-9]{64}$/']]);
        $statements->recordAgreement($this->merchant($merchant)->id, (string) $d['fee_schedule_id'], (string) $d['signer_name'], (string) $d['document_hash'], $this->actor());

        return back()->with('status', 'Signed fee agreement recorded.');
    }

    public function runValidation(string $merchant, ValidationRunner $runner): RedirectResponse
    {
        $run = $runner->run($this->merchant($merchant), $this->actor());

        return back()->with($run->status === 'passed' ? 'status' : 'error', $run->status === 'passed'
            ? 'Validation passed; merchant moved to validation.'
            : 'Validation failed: '.implode(', ', array_keys(array_filter($run->checks, fn ($c) => ! $c['passed']))));
    }

    public function requestGoLive(string $merchant, GoLiveGate $gate): RedirectResponse
    {
        $result = $gate->request($this->merchant($merchant), $this->userId(), $this->actor());
        if ($result['approval'] === null) {
            $failing = collect($result['evaluation']['checks'])->where('passed', false)->pluck('label')->implode('; ');

            return back()->with('error', 'Go-live checks failed: '.$failing);
        }

        return back()->with('status', 'All checks passed. A second authorized person must approve in Approvals.');
    }

    public function requestOverride(Request $request, string $merchant, GoLiveGate $gate): RedirectResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:20', 'max:1000'], 'step_up_code' => ['required', 'string', 'max:8']]);
        $gate->requestOverride($this->merchant($merchant), $this->userId(), (string) $d['step_up_code'], (string) $d['reason'], $this->actor());

        return back()->with('status', 'Emergency override requested. A second authorized person must approve it.');
    }

    public function suspend(Request $request, string $merchant, MerchantLifecycle $lifecycle): RedirectResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
        DB::transaction(fn () => $lifecycle->apply($this->merchant($merchant), 'merchant.suspended', [], $reason, null, $this->actor()));

        return back()->with('status', 'Merchant suspended.');
    }

    public function reinstate(Request $request, string $merchant, MerchantLifecycle $lifecycle): RedirectResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
        $lifecycle->apply($this->merchant($merchant), 'merchant.reinstated', [], $reason, null, $this->actor());

        return back()->with('status', 'Merchant reinstated.');
    }
}
