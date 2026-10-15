<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Support\Amount;
use App\Support\Ids;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Approvals\Contracts\ApprovalRequests;
use Modules\Events\Contracts\EventLog;
use Modules\Onboarding\Application\DocumentChecklist;
use Modules\Onboarding\Application\GoLiveGate;
use Modules\Onboarding\Application\HandoffPayload;
use Modules\Onboarding\Application\HandoffService;
use Modules\Onboarding\Application\RiskReview;
use Modules\Onboarding\Application\ValidationRunner;
use Modules\Onboarding\Domain\MerchantStateMachine;
use Modules\Onboarding\Infrastructure\GoLiveEvaluation;
use Modules\Onboarding\Infrastructure\KybCheck;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Onboarding\Infrastructure\MerchantDocument;
use Modules\Payments\Contracts\PaymentReports;
use Modules\Routing\Contracts\Eligibility;
use Modules\Routing\Contracts\MerchantAccounts;
use Modules\Settlement\Infrastructure\FeeAgreement;
use Modules\Settlement\Infrastructure\FeeSchedule;

final class MerchantController extends AdminController
{
    public function index(Request $request): View
    {
        $q = Merchant::query()->orderByDesc('created_at');
        if (is_string($state = $request->query('state')) && $state !== '') {
            $q->where('state', $state);
        }
        if (is_string($s = $request->query('q')) && trim($s) !== '') {
            $term = '%'.mb_strtolower(trim($s)).'%';
            $q->where(fn ($w) => $w->whereRaw('lower(legal_name) like ?', [$term])->orWhereRaw('lower(coalesce(dba, \'\')) like ?', [$term])
                ->orWhere('public_id', 'like', '%'.trim($s).'%')->orWhere('crm_deal_id', trim($s))->orWhere('id', trim($s)));
        }

        return view('admin.merchants.index', ['merchants' => $q->paginate(25)->withQueryString(), 'states' => MerchantStateMachine::STATES]);
    }

    public function create(): View
    {
        return view('admin.merchants.create', ['formKey' => Ids::new('form')]);
    }

    /** Manual "Add merchant" uses the same canonical package and service as the CRM handoff (source = manual). */
    public function store(Request $request, HandoffService $handoff): RedirectResponse
    {
        $input = $request->all();
        $payload = [
            'crm_deal_id' => $input['crm_deal_id'] ?? null ?: null,
            'merchant_type' => $input['merchant_type'] ?? 'standard',
            'business' => [
                'legal_name' => $input['legal_name'] ?? null, 'dba' => $input['dba'] ?? null, 'ein' => $input['ein'] ?? null,
                'entity_type' => $input['entity_type'] ?? null, 'website' => $input['website'] ?? null ?: null, 'phone' => $input['phone'] ?? null,
                'address' => ['line1' => $input['line1'] ?? null, 'city' => $input['city'] ?? null, 'region' => $input['region'] ?? null,
                    'postal_code' => $input['postal_code'] ?? null, 'country' => strtoupper((string) ($input['country'] ?? 'US'))],
            ],
            'contacts' => [['name' => $input['contact_name'] ?? null, 'email' => $input['contact_email'] ?? null, 'role' => 'primary']],
            'owners' => [['name' => $input['owner_name'] ?? null, 'ownership_percent' => (int) ($input['ownership_percent'] ?? 100), 'identity_ref' => $input['owner_identity_ref'] ?? 'manual-entry']],
            'primary_user' => ['name' => $input['contact_name'] ?? null, 'email' => $input['contact_email'] ?? null],
            'banking' => ['settlement_token_ref' => $input['bank_token_ref'] ?? null, 'last4' => $input['bank_last4'] ?? null ?: null],
            'commerce' => [
                'mcc' => $input['mcc'] ?? null, 'products' => $input['products'] ?? null,
                'monthly_volume_minor' => Amount::toMinor($input['monthly_volume'] ?? null),
                'avg_ticket_minor' => Amount::toMinor($input['avg_ticket'] ?? null),
                'max_ticket_minor' => Amount::toMinor($input['max_ticket'] ?? null),
                'currency' => strtoupper((string) ($input['currency'] ?? 'USD')),
                'channels' => array_values((array) ($input['channels'] ?? [])),
            ],
            'agent' => ['agent_id' => $input['agent_id'] ?? 'house', 'name' => $input['agent_name'] ?? 'House account'],
        ];
        $data = HandoffPayload::validate($payload, requireDealId: false);
        [, $body] = $handoff->handle($data, (string) $request->input('form_key', Ids::new('form')), 'manual');

        return redirect()->route('admin.merchants.show', $body['id'])->with('status', 'Merchant created. Sandbox provisioned and invitation emailed.');
    }

    public function show(string $merchant, DocumentChecklist $checklist, RiskReview $risk, Eligibility $eligibility, MerchantAccounts $accounts,
        EventLog $events, PaymentReports $reports, ValidationRunner $validation, ApprovalRequests $approvals): View
    {
        $m = $this->merchant($merchant);
        $lastEval = GoLiveEvaluation::query()->where('merchant_id', $m->id)->orderByDesc('id')->first();

        return view('admin.merchants.show', [
            'm' => $m,
            'progress' => MerchantStateMachine::PROGRESS,
            'checklist' => $checklist->items($m),
            'checklistSummary' => $checklist->summary($m),
            'documents' => MerchantDocument::query()->where('merchant_id', $m->id)->orderByDesc('created_at')->get(),
            'kyb' => KybCheck::query()->where('merchant_id', $m->id)->orderByDesc('created_at')->first(),
            'risk' => $risk->latest($m),
            'eligibility' => $eligibility->latest($m->id),
            'accounts' => $accounts->forMerchant($m->id),
            'events' => array_reverse($events->forSubjects([$m->id], 300)),
            'payments' => $reports->merchantSummary($m->id, 30, 'sandbox'),
            'livePayments' => $reports->merchantSummary($m->id, 30, 'live'),
            'validation' => $validation->latestPassed($m),
            'goLive' => $lastEval,
            'goLiveChecks' => GoLiveGate::CHECKS,
            'pendingGoLive' => $approvals->pendingFor('go_live', $m->id) ?? $approvals->pendingFor('go_live_override', $m->id),
            'feeSchedules' => FeeSchedule::query()->orderBy('version')->get(),
            'agreement' => FeeAgreement::query()->where('merchant_id', $m->id)->orderByDesc('signed_at')->first(),
            'connectors' => array_keys(array_filter((array) config('connectors'), fn ($c, $k) => is_array($c) && isset($c['environments']) && in_array('live', (array) $c['environments'], true), ARRAY_FILTER_USE_BOTH)),
        ]);
    }
}
