<?php

declare(strict_types=1);

use App\Support\Actor;
use App\Support\FeatureFlags;
use Illuminate\Support\Facades\DB;
use Modules\Events\Infrastructure\OutboxEvent;
use Modules\Identity\Contracts\ApiKeyIssuing;
use Modules\Identity\Contracts\IssuedKeyPair;
use Modules\Identity\Infrastructure\ApiKey;
use Modules\Onboarding\Application\SandboxProvisioner;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Routing\Infrastructure\MerchantAccount;

beforeEach(fn () => $this->seedPlatform());

function postHandoff($t, array $payload, string $key, ?string $token = null)
{
    return $t->withToken($token ?? $t->serviceToken())->withHeader('Idempotency-Key', $key)->postJson('/v1/merchants/handoff', $payload);
}

it('creates the merchant at submitted with sandbox MID, key pair and webhook secret in one call', function () {
    $body = postHandoff($this, $this->handoffPayload(['crm_deal_id' => 'D-10482']), 'h-1')->assertCreated()->json();
    $this->asTenant();
    $m = Merchant::query()->findOrFail($body['id']);
    expect($m->state)->toBe('submitted')->and($m->crm_deal_id)->toBe('D-10482')->and($m->public_id)->toStartWith('EDP-M-')
        ->and($m->sandbox_webhook_secret)->toStartWith('arwhk_sbx_')
        ->and(MerchantAccount::query()->where('merchant_id', $m->id)->where('environment', 'sandbox')->count())->toBe(1)
        ->and(ApiKey::query()->where('merchant_id', $m->id)->pluck('display_prefix')->implode(' '))->toContain('arpub_sbx_')
        ->and($m->agent['agent_id'])->toBe('agt_42');
    expect(OutboxEvent::query()->where('subject_id', $m->id)->pluck('type')->all())
        ->toContain('application.created', 'merchant.submitted', 'merchant.sandbox_provisioned');
    // PII is encrypted at rest; EIN is matched by fingerprint.
    $raw = DB::table('merchants')->where('id', $m->id)->first();
    expect($raw->ein)->not->toContain('3456789')->and($raw->owners)->not->toContain('Ana Owner')->and($raw->ein_fingerprint)->toHaveLength(64);
});

it('never duplicates on a repeated Idempotency-Key or a repeated crm_deal_id', function () {
    $payload = $this->handoffPayload(['crm_deal_id' => 'D-777']);
    $first = postHandoff($this, $payload, 'same-key')->assertCreated()->json();
    $replay = postHandoff($this, $payload, 'same-key')->assertCreated()->json();
    $byDeal = postHandoff($this, $payload, 'other-key')->assertOk()->json();
    expect($replay['id'])->toBe($first['id'])->and($replay['replayed'])->toBeTrue()->and($byDeal['id'])->toBe($first['id']);

    postHandoff($this, $this->handoffPayload(['crm_deal_id' => 'D-778']), 'same-key')->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_reused');
    $this->asTenant();
    expect(Merchant::query()->count())->toBe(1)
        ->and(MerchantAccount::query()->count())->toBe(1)
        ->and(ApiKey::query()->count())->toBe(2)
        ->and(OutboxEvent::query()->where('type', 'application.created')->count())->toBe(1);
});

it('rejects incomplete packages all-or-nothing with the standard error shape', function () {
    $payload = $this->handoffPayload();
    unset($payload['owners'], $payload['business']['ein']);
    postHandoff($this, $payload, 'bad-1')->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['type', 'code', 'message', 'request_id', 'details' => ['owners', 'business.ein']]]);
    $this->asTenant();
    expect(Merchant::query()->count())->toBe(0)->and(DB::table('org_nodes')->where('type', 'merchant')->count())->toBe(0);
});

it('rejects raw bank fields and card-number-like values', function () {
    postHandoff($this, $this->handoffPayload(['banking' => ['account_number' => '000123456789']]), 'bank-1')
        ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['banking.account_number']]]);
    postHandoff($this, $this->handoffPayload(['commerce' => ['products' => 'test card 4242 4242 4242 4242']]), 'pan-1')
        ->assertStatus(422);
    $this->asTenant();
    expect(Merchant::query()->count())->toBe(0);
});

it('accepts only service tokens with the crm.handoff scope', function () {
    $m = $this->handoff();
    $merchantKey = $this->sandboxSecret($m['id']);
    postHandoff($this, $this->handoffPayload(), 'k1', $merchantKey)->assertStatus(401);
    postHandoff($this, $this->handoffPayload(), 'k2', 'arsvt_not-a-real-token')->assertStatus(401);
    postHandoff($this, $this->handoffPayload(), 'k3', $this->serviceToken(self::TENANT, 'merchants.read'))->assertStatus(403)->assertJsonPath('error.code', 'insufficient_scope');
});

it('keeps tenants isolated: another organisation\'s CRM cannot see or collide with our merchants', function () {
    $ours = postHandoff($this, $this->handoffPayload(['crm_deal_id' => 'D-1']), 'iso-1')->assertCreated()->json();
    $theirs = postHandoff($this, $this->handoffPayload(['crm_deal_id' => 'D-1']), 'iso-1', $this->serviceToken(self::OTHER_TENANT))->assertCreated()->json();
    expect($theirs['id'])->not->toBe($ours['id']);
    $this->withToken($this->serviceToken(self::OTHER_TENANT))->getJson('/v1/merchants/'.$ours['id'])->assertNotFound();
    $this->asTenant(self::OTHER_TENANT);
    expect(Merchant::query()->pluck('id')->all())->toBe([$theirs['id']]);
});

it('is off behind the crm_handoff flag', function () {
    app(FeatureFlags::class)->set('crm_handoff', false, Actor::system('test'), 'pilot not started');
    postHandoff($this, $this->handoffPayload(), 'f-1')->assertStatus(403)->assertJsonPath('error.code', 'feature_disabled');
});

it('retries provisioning without duplicating MIDs, keys or events', function () {
    $body = $this->handoff();
    $this->asTenant();
    $m = Merchant::query()->findOrFail($body['id']);
    $events = OutboxEvent::query()->count();
    DB::transaction(fn () => app(SandboxProvisioner::class)->provision($m));
    DB::transaction(fn () => app(SandboxProvisioner::class)->provision($m->refresh()));
    expect(MerchantAccount::query()->where('merchant_id', $m->id)->count())->toBe(1)
        ->and(ApiKey::query()->where('merchant_id', $m->id)->count())->toBe(2)
        ->and(OutboxEvent::query()->count())->toBe($events);
});

it('rolls back everything when provisioning fails mid-way (no orphans)', function () {
    $this->app->bind(ApiKeyIssuing::class, fn () => new class implements ApiKeyIssuing
    {
        public function issuePair(string $tenantId, string $merchantId, string $environment, ?string $createdBy = null): IssuedKeyPair
        {
            throw new RuntimeException('key service down');
        }
    });
    postHandoff($this, $this->handoffPayload(), 'rb-1')->assertStatus(500)->assertJsonMissingPath('error.trace');
    $this->asTenant();
    expect(Merchant::query()->count())->toBe(0)->and(MerchantAccount::query()->count())->toBe(0)
        ->and(OutboxEvent::query()->count())->toBe(0)->and(DB::table('merchant_index')->count())->toBe(0)
        ->and(DB::table('idempotency_keys')->count())->toBe(0);
});

it('locks crm_deal_id and the original agent', function () {
    $body = $this->handoff();
    $this->asTenant();
    $m = Merchant::query()->findOrFail($body['id']);
    expect(fn () => $m->forceFill(['crm_deal_id' => 'D-hijack'])->save())->toThrow(LogicException::class);
    expect(fn () => $m->refresh()->forceFill(['agent' => ['agent_id' => 'agt_other', 'name' => 'X']])->save())->toThrow(LogicException::class);
});
