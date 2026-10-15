<?php

declare(strict_types=1);

use App\Support\Actor;
use App\Support\ApiException;
use App\Support\FeatureFlags;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\Approvals\Application\ApprovalService;
use Modules\Approvals\Contracts\ApprovalDenied;
use Modules\Events\Infrastructure\OutboxEvent;
use Modules\Identity\Infrastructure\User;
use Modules\Onboarding\Application\CommandCenter;
use Modules\Onboarding\Application\DocumentChecklist;
use Modules\Onboarding\Application\GoLiveGate;
use Modules\Onboarding\Application\MerchantLifecycle;
use Modules\Onboarding\Application\ProcessorBoarding;
use Modules\Onboarding\Application\RiskReview;
use Modules\Onboarding\Application\ValidationRunner;
use Modules\Onboarding\Domain\IllegalTransition;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Settlement\Infrastructure\FeeSchedule;
use Modules\Tenancy\Contracts\TenantContext;
use Modules\Tenancy\Contracts\TenantScope;
use Modules\Webhooks\Application\WebhookService;

beforeEach(function () {
    $this->seedPlatform();
    Storage::fake('documents');
    Http::fake(['https://merchant.example/*' => Http::response('ok', 200)]);
    [$this->ops, $this->opsTotp] = $this->adminUser('operations');
    [$this->approver, $this->approverTotp] = $this->adminUser('owner');
    [$this->underwriter, $this->uwTotp] = $this->adminUser('underwriter');
    $this->secrets->put('amplirail/live/mer/credential', 'provider-security-key');
});

function actAs(User $u): Actor
{
    $actor = new Actor('user', $u->id, $u->name);
    app(TenantScope::class)->set(new TenantContext('org_edatapay', '/org_edatapay/', null, $actor));

    return $actor;
}

function pdf(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('doc.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
}

/** Drives one merchant from CRM handoff to validation; returns the merchant. */
function toValidation($t): Merchant
{
    $m = $t->handoff();
    $actor = actAs($t->ops);
    $merchant = Merchant::query()->findOrFail($m['id']);
    $checklist = app(DocumentChecklist::class);
    foreach (['government_id', 'bank_letter', 'business_registration'] as $type) {
        $checklist->upload($merchant->refresh(), pdf(), $type, $actor);
    }
    expect($merchant->refresh()->state)->toBe('kyb_pending');
    $t->relay();                                   // documents.complete -> RunKybChecks (sync queue) -> kyb.passed
    actAs($t->underwriter);
    expect($merchant->refresh()->state)->toBe('underwriting');
    app(RiskReview::class)->decide($merchant, 'approve', 'Standard restaurant, low risk', $t->underwriter->id, $t->totp($t->uwTotp), true, actAs($t->underwriter));
    expect($merchant->refresh()->state)->toBe('approved');

    $actor = actAs($t->ops);
    $boarding = app(ProcessorBoarding::class);
    $eligibility = $boarding->evaluateEligibility($merchant);
    expect(collect($eligibility['eligible'])->pluck('code'))->toContain('nmi_bridge:us');
    $boarding->submit($merchant->refresh(), 'nmi_bridge:us', $actor);
    $boarding->recordApproval($merchant->refresh(), 'Approved by processor portal', $actor);
    $account = $boarding->recordMid($merchant->refresh(), 'nmi_bridge', '887766554433', 'T001', 'COASTALGRILL', $actor);
    $boarding->recordCredentials($merchant->refresh(), $account->id, 'amplirail/live/mer/credential', $actor);
    expect($merchant->refresh()->state)->toBe('test_mode');

    $created = app(WebhookService::class)->create($merchant->id, 'sandbox', 'https://merchant.example/hooks', ['transaction.approved', 'webhook.test'], $actor);
    app(WebhookService::class)->sendTest($created['endpoint']);
    actAs($t->ops);
    $run = app(ValidationRunner::class)->run($merchant->refresh(), $actor);
    expect($run->status)->toBe('passed')->and($merchant->refresh()->state)->toBe('validation');

    $schedule = FeeSchedule::query()->firstOrFail();
    $boarding->configure($merchant->refresh(), ['descriptor' => 'COASTAL GRILL', 'pci_status' => 'compliant', 'pci_expires_at' => now()->addYear()->toDateString(),
        'fee_schedule_id' => $schedule->id, 'payout_method' => 'ach', 'funding_schedule' => 'weekly', 'settlement_verified' => '1', 'reserve_bps' => 0], $actor);

    return $merchant->refresh();
}

it('runs the 4-day activation from CRM handoff to live with dual control and emits the 12 lifecycle events', function () {
    $merchant = toValidation($this);
    $requester = actAs($this->ops);
    $result = app(GoLiveGate::class)->request($merchant, $this->ops->id, $requester);
    expect($result['evaluation']['passed'])->toBeTrue()->and($result['approval'])->not->toBeNull();

    actAs($this->approver);
    $decided = app(ApprovalService::class)->approve($result['approval']->id, $this->approver->id, $this->totp($this->approverTotp), 'All checks verified');
    expect($decided->status)->toBe('approved')->and($merchant->refresh()->state)->toBe('active');

    $types = OutboxEvent::query()->where('subject_id', $merchant->id)->orderBy('id')->pluck('type')->all();
    $expected = ['application.created', 'merchant.sandbox_provisioned', 'documents.complete', 'kyb.passed', 'risk.approved', 'processor.submitted',
        'processor.approved', 'mid.created', 'credentials.created', 'test.completed', 'merchant.live'];
    $positions = array_map(fn ($e) => array_search($e, $types, true), $expected);
    expect($positions)->not->toContain(false);
    $sorted = $positions;
    sort($sorted);
    expect($positions)->toBe($sorted);
    foreach ($expected as $e) {
        expect(count(array_keys($types, $e, true)))->toBe(1, "$e emitted once");
    }
    $live = OutboxEvent::query()->where('subject_id', $merchant->id)->where('type', 'merchant.live')->firstOrFail();
    expect($live->envelope())->toHaveKeys(['correlation_id', 'actor', 'org_path', 'environment', 'subject', 'data'])
        ->and($live->data['approved_by'])->toBe($this->approver->id)
        ->and($live->data['requested_by'])->toBe($this->ops->id);
});

it('emits processor.more_info_required, reopens the checklist and resumes boarding when satisfied', function () {
    $m = $this->handoff();
    $actor = actAs($this->ops);
    $merchant = Merchant::query()->findOrFail($m['id']);
    foreach (['government_id', 'bank_letter', 'business_registration'] as $type) {
        app(DocumentChecklist::class)->upload($merchant->refresh(), pdf(), $type, $actor);
    }
    $this->relay();
    $uw = actAs($this->underwriter); // set the tenant context BEFORE reading (refresh() skips Eloquent scopes; RLS does not)
    app(RiskReview::class)->decide($merchant->refresh(), 'approve', 'ok to proceed', $this->underwriter->id, $this->totp($this->uwTotp), true, $uw);
    $actor = actAs($this->ops);
    app(ProcessorBoarding::class)->submit($merchant->refresh(), 'nmi_bridge:us', $actor);

    app(DocumentChecklist::class)->requestMoreInfo($merchant->refresh(), 'ownership_proof', 'Proof of ownership', 'Processor asked for ownership evidence', 'processor', $actor);
    expect($merchant->refresh()->state)->toBe('needs_info')
        ->and(app(DocumentChecklist::class)->summary($merchant)['outstanding'])->toContain('Proof of ownership');
    expect(OutboxEvent::query()->where('subject_id', $merchant->id)->where('type', 'processor.more_info_required')->count())->toBe(1);

    app(DocumentChecklist::class)->upload($merchant->refresh(), pdf(), 'ownership_proof', $actor);
    expect($merchant->refresh()->state)->toBe('boarding');
});

it('blocks illegal jumps and makes replayed transitions no-ops', function () {
    $m = $this->handoff();
    $actor = actAs($this->ops);
    $merchant = Merchant::query()->findOrFail($m['id']);
    $lifecycle = app(MerchantLifecycle::class);

    expect(fn () => $lifecycle->apply($merchant, 'risk.approved'))->toThrow(IllegalTransition::class);
    expect(fn () => $lifecycle->apply($merchant, 'credentials.created'))->toThrow(IllegalTransition::class);
    expect(DB::table('audit_log')->where('action', 'onboarding.illegal_transition')->count())->toBe(2);

    $before = OutboxEvent::query()->count();
    $replay = $lifecycle->apply($merchant->refresh(), 'application.created');
    expect($replay->changed)->toBeFalse()->and(OutboxEvent::query()->count())->toBe($before);
    $sandbox = $lifecycle->apply($merchant->refresh(), 'merchant.sandbox_provisioned');
    expect($sandbox->changed)->toBeFalse()->and(OutboxEvent::query()->count())->toBe($before);
});

it('refuses a direct state write that bypasses the state machine', function () {
    $m = $this->handoff();
    actAs($this->ops);
    $merchant = Merchant::query()->findOrFail($m['id']);
    $merchant->state = 'active';
    expect(fn () => $merchant->save())->toThrow(LogicException::class);
    expect(fn () => app(MerchantLifecycle::class)->apply($merchant->refresh(), 'merchant.live'))->toThrow(IllegalTransition::class);
});

it('requires an approved dual-control approval for merchant.live, even from validation', function () {
    $merchant = toValidation($this);
    actAs($this->ops);
    expect(fn () => app(MerchantLifecycle::class)->apply($merchant, 'merchant.live', [], null, 'apr_forged'))->toThrow(IllegalTransition::class);
    expect($merchant->refresh()->state)->toBe('validation');
});

it('stops the requester from approving their own go-live and users without the permission', function () {
    $merchant = toValidation($this);
    $approval = app(GoLiveGate::class)->request($merchant, $this->ops->id, actAs($this->ops))['approval'];

    actAs($this->ops);
    expect(fn () => app(ApprovalService::class)->approve($approval->id, $this->ops->id, $this->totp($this->opsTotp), 'self approve'))
        ->toThrow(ApprovalDenied::class, 'The requester cannot approve their own request.');
    actAs($this->underwriter);
    expect(fn () => app(ApprovalService::class)->approve($approval->id, $this->underwriter->id, $this->totp($this->uwTotp), 'no permission'))
        ->toThrow(ApprovalDenied::class);
    actAs($this->approver);
    expect(fn () => app(ApprovalService::class)->approve($approval->id, $this->approver->id, '000000', 'wrong code'))->toThrow(ApprovalDenied::class);
    expect(fn () => app(ApprovalService::class)->approve($approval->id, $this->approver->id, $this->totp($this->approverTotp), ''))->toThrow(ApprovalDenied::class);
    expect($merchant->refresh()->state)->toBe('validation');
});

it('blocks a cross-tenant approver', function () {
    $merchant = toValidation($this);
    $approval = app(GoLiveGate::class)->request($merchant, $this->ops->id, actAs($this->ops))['approval'];
    [$foreign, $foreignTotp] = $this->adminUser('owner', self::OTHER_TENANT);
    actAs($this->approver); // the item lives in org_edatapay; approver from org_other has no membership there
    expect(fn () => app(ApprovalService::class)->approve($approval->id, $foreign->id, $this->totp($foreignTotp), 'cross tenant'))
        ->toThrow(ApprovalDenied::class);
});

it('fails each go-live check on its own and keeps the merchant in validation', function (string $check, Closure $break) {
    $merchant = toValidation($this);
    actAs($this->ops);
    $break($merchant, $this);
    $result = app(GoLiveGate::class)->request($merchant->refresh(), $this->ops->id, actAs($this->ops));
    $failing = collect($result['evaluation']['checks'])->where('passed', false)->pluck('code')->all();
    expect($result['approval'])->toBeNull()->and($failing)->toContain($check);
    expect($merchant->refresh()->state)->toBe('validation');
})->with([
    'settlement' => ['settlement', fn ($m) => DB::table('merchants')->where('id', $m->id)->update(['settlement_verified_at' => null])],
    'risk controls' => ['risk_controls', fn ($m) => DB::table('merchants')->where('id', $m->id)->update(['descriptor' => null])],
    'pci' => ['pci', fn ($m) => DB::table('merchants')->where('id', $m->id)->update(['pci_expires_at' => now()->subDay()->toDateString()])],
    'credentials unreadable (fail closed)' => ['credentials', fn ($m, $t) => $t->secrets->unavailable = true],
    'webhooks' => ['webhooks', fn ($m) => DB::table('webhook_deliveries')->where('merchant_id', $m->id)->update(['status' => 'failed'])],
    'test sale' => ['test_sale', fn ($m) => DB::table('validation_runs')->where('merchant_id', $m->id)->update(['status' => 'failed'])],
]);

it('fails closed when the go-live gate flag is off: no request and no activation', function () {
    $merchant = toValidation($this);
    $approval = app(GoLiveGate::class)->request($merchant, $this->ops->id, actAs($this->ops))['approval'];
    app(FeatureFlags::class)->set('go_live_gate', false, Actor::system('test'), 'incident drill');

    expect(fn () => app(GoLiveGate::class)->request($merchant, $this->ops->id, actAs($this->ops)))->toThrow(ApiException::class);
    actAs($this->approver);
    $decided = app(ApprovalService::class)->approve($approval->id, $this->approver->id, $this->totp($this->approverTotp), 'approve while gate down');
    expect($decided->status)->toBe('failed')->and($merchant->refresh()->state)->toBe('validation');
});

it('allows an emergency override only from the Platform Owner plus a second approver, and audits it', function () {
    $merchant = toValidation($this);
    [$owner, $ownerTotp] = $this->adminUser('platform_owner');
    expect(fn () => app(GoLiveGate::class)->requestOverride($merchant, $this->ops->id, $this->totp($this->opsTotp), 'Processor portal outage, pilot launch needed today', actAs($this->ops)))
        ->toThrow(ApiException::class);

    $approval = app(GoLiveGate::class)->requestOverride($merchant, $owner->id, $this->totp($ownerTotp), 'Processor portal outage, pilot launch needed today', actAs($owner));
    actAs($this->approver);
    app(ApprovalService::class)->approve($approval->id, $this->approver->id, $this->totp($this->approverTotp), 'Second human confirms override');
    expect($merchant->refresh()->state)->toBe('active')
        ->and(DB::table('audit_log')->where('action', 'go_live.emergency_override')->count())->toBe(1);
});

it('computes the command center from source data', function () {
    $this->handoff();
    $merchant = toValidation($this);
    $snap = app(CommandCenter::class)->snapshot();
    $buckets = collect($snap['pipeline'])->keyBy('bucket');
    expect($buckets['New']['count'])->toBe(1)
        ->and($buckets['Test']['count'])->toBe(1)
        ->and(collect($snap['merchants'])->firstWhere('merchant_id', $merchant->id)['blocker'])->toBe('Go-live not requested')
        ->and(collect($snap['time_in_state']['per_state'])->pluck('state'))->toContain('submitted')
        ->and(collect($snap['health']['connectors'])->pluck('connector'))->toContain('simulator');
});
