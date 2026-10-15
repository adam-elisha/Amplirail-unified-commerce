<?php

declare(strict_types=1);

use AmpliRail\Webhooks\WebhookSigner;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Modules\Events\Infrastructure\OutboxEvent;
use Modules\Identity\Contracts\ApiKeyIssuing;
use Modules\Ledger\Contracts\Ledger;
use Modules\Payments\Infrastructure\Transaction;
use Modules\Tenancy\Contracts\TenantScope;
use Modules\Webhooks\Infrastructure\WebhookDelivery;

/*
 * Master document Section 20 / V3.7.1 Milestone #1:
 * create a merchant -> sandbox provisioned -> $100 simulator transaction -> transaction event -> balanced ledger journal
 * -> signed webhook -> complete event timeline. No real card data.
 */

beforeEach(function () {
    $this->seedPlatform();
    Http::fake(['https://merchant.example/*' => Http::response('ok', 200)]);
});

it('runs Milestone #1 end to end through the API', function () {
    $merchant = $this->handoff();
    expect($merchant['state'])->toBe('submitted')
        ->and($merchant['sandbox']['test_mid'])->toStartWith('TMID-')
        ->and($merchant['sandbox']['publishable_key'])->toStartWith('arpub_sbx_')
        ->and($merchant['sandbox']['secret_key_hint'])->toStartWith('arsec_sbx_');

    $secret = $this->sandboxSecret($merchant['id']);

    $endpoint = $this->withToken($secret)->withHeader('Idempotency-Key', 'whk-1')
        ->postJson('/v1/webhook_endpoints', ['url' => 'https://merchant.example/hooks', 'events' => ['transaction.approved']])
        ->assertCreated()->json();
    expect($endpoint['secret'])->toStartWith('arwhk_sbx_');

    $txn = $this->withToken($secret)->withHeader('Idempotency-Key', 'order-100')
        ->postJson('/v1/transactions', ['amount' => ['amount_minor' => 10000, 'currency' => 'USD'], 'payment_token' => 'tok_sim_approved', 'order_reference' => 'ORD-100'])
        ->assertCreated()->json();
    expect($txn['status'])->toBe('approved')->and($txn['state'])->toBe('captured')->and($txn['amount_minor'])->toBe(10000);

    // Same idempotency key: same transaction, no second charge.
    $again = $this->withToken($secret)->withHeader('Idempotency-Key', 'order-100')
        ->postJson('/v1/transactions', ['amount' => ['amount_minor' => 10000, 'currency' => 'USD'], 'payment_token' => 'tok_sim_approved', 'order_reference' => 'ORD-100'])
        ->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->json();
    expect($again['id'])->toBe($txn['id']);

    $this->asTenant();
    expect(Transaction::query()->where('merchant_id', $merchant['id'])->count())->toBe(1)
        ->and(app(Ledger::class)->isBalanced())->toBeTrue()
        ->and(app(Ledger::class)->balance('merchant_payable:'.$merchant['id'], 'USD', 'sandbox'))->toBe(-10000)
        ->and(OutboxEvent::query()->where('subject_id', $txn['id'])->value('type'))->toBe('transaction.approved');

    // Relay publishes the event; the webhook fan-out delivers a signed webhook.
    expect($this->relay())->toBeGreaterThan(0);
    Http::assertSent(function (HttpRequest $r) use ($endpoint, $txn) {
        return $r->url() === 'https://merchant.example/hooks'
            && WebhookSigner::verify($endpoint['secret'], $r->body(), $r->header('AmpliRail-Signature')[0], time())
            && json_decode($r->body(), true)['data']['transaction_id'] === $txn['id'];
    });
    $this->asTenant();
    expect(WebhookDelivery::query()->where('status', 'delivered')->count())->toBe(1);
    // Relaying again publishes nothing and delivers nothing twice.
    expect($this->relay())->toBe(0);

    $timeline = $this->withToken($secret)->getJson('/v1/transactions/'.$txn['id'].'/timeline')->assertOk()->json();
    expect(collect($timeline['events'])->pluck('type')->all())->toContain('transaction.approved')
        ->and($timeline['ledger'])->toHaveCount(2)
        ->and($timeline['events'][0]['correlation_id'])->toStartWith('corr_');
});

it('routes forced decline, timeout and outage scenarios as specified', function () {
    $merchant = $this->handoff();
    $secret = $this->sandboxSecret($merchant['id']);
    $pay = fn (int $minor, string $key, array $extra = []) => $this->withToken($secret)->withHeader('Idempotency-Key', $key)
        ->postJson('/v1/transactions', array_merge(['amount' => ['amount_minor' => $minor, 'currency' => 'USD'], 'payment_token' => 'tok_sim_approved', 'order_reference' => $key], $extra))->json();

    expect($pay(1002, 'k-decline'))->toMatchArray(['status' => 'declined', 'response_code' => '05', 'decline_category' => 'do_not_honor'])
        ->and($pay(1051, 'k-soft'))->toMatchArray(['status' => 'declined', 'decline_category' => 'insufficient_funds'])
        ->and($pay(1059, 'k-fraud'))->toMatchArray(['status' => 'declined', 'decline_category' => 'fraud_suspected'])
        ->and($pay(1099, 'k-timeout'))->toMatchArray(['status' => 'failed', 'reason' => 'all_routes_unavailable'])
        ->and($pay(1000, 'k-outage', ['simulator' => 'sim_outage']))->toMatchArray(['status' => 'failed'])
        ->and($pay(1000, 'k-avs', ['simulator' => 'sim_avs_fail']))->toMatchArray(['status' => 'approved']);
});

it('rejects card numbers anywhere in a payment request', function () {
    $merchant = $this->handoff();
    $secret = $this->sandboxSecret($merchant['id']);
    $this->withToken($secret)->withHeader('Idempotency-Key', 'pan-1')
        ->postJson('/v1/transactions', ['amount' => ['amount_minor' => 1000, 'currency' => 'USD'], 'payment_token' => '4111 1111 1111 1111', 'order_reference' => 'x'])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    $this->asTenant();
    expect(Transaction::query()->count())->toBe(0);
});

it('requires an idempotency key on every POST', function () {
    $merchant = $this->handoff();
    $secret = $this->sandboxSecret($merchant['id']);
    $this->withoutHeader('Idempotency-Key')->withToken($secret)
        ->postJson('/v1/transactions', ['amount' => ['amount_minor' => 1000, 'currency' => 'USD'], 'payment_token' => 'tok_sim_approved', 'order_reference' => 'x'])
        ->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_required')->assertJsonStructure(['error' => ['request_id']]);
});

it('refunds partially and rejects over-refunds, keeping the ledger balanced', function () {
    $merchant = $this->handoff();
    $secret = $this->sandboxSecret($merchant['id']);
    $txn = $this->withToken($secret)->withHeader('Idempotency-Key', 'r-sale')
        ->postJson('/v1/transactions', ['amount' => ['amount_minor' => 2500, 'currency' => 'USD'], 'payment_token' => 'tok_sim_approved', 'order_reference' => 'R1'])->json();

    $this->withToken($secret)->withHeader('Idempotency-Key', 'r-1')
        ->postJson('/v1/refunds', ['transaction_id' => $txn['id'], 'amount' => ['amount_minor' => 1000, 'currency' => 'USD']])
        ->assertCreated()->assertJsonPath('status', 'succeeded')->assertJsonPath('transaction.state', 'partially_refunded');
    $this->withToken($secret)->withHeader('Idempotency-Key', 'r-2')
        ->postJson('/v1/refunds', ['transaction_id' => $txn['id'], 'amount' => ['amount_minor' => 2000, 'currency' => 'USD']])
        ->assertStatus(422)->assertJsonPath('error.code', 'over_refundable_amount');

    $this->asTenant();
    expect(app(Ledger::class)->isBalanced())->toBeTrue()
        ->and(app(Ledger::class)->balance('merchant_payable:'.$merchant['id'], 'USD', 'sandbox'))->toBe(-1500);
});

it('authorizes, captures and voids through the transaction state machine', function () {
    $merchant = $this->handoff();
    $secret = $this->sandboxSecret($merchant['id']);
    $auth = $this->withToken($secret)->withHeader('Idempotency-Key', 'a-1')
        ->postJson('/v1/transactions', ['amount' => ['amount_minor' => 5000, 'currency' => 'USD'], 'payment_token' => 'tok_sim_approved', 'order_reference' => 'A1', 'capture' => false])->json();
    expect($auth['state'])->toBe('authorized');
    $this->withToken($secret)->withHeader('Idempotency-Key', 'a-cap')->postJson('/v1/transactions/'.$auth['id'].'/capture', [])
        ->assertOk()->assertJsonPath('state', 'captured');

    $auth2 = $this->withToken($secret)->withHeader('Idempotency-Key', 'a-2')
        ->postJson('/v1/transactions', ['amount' => ['amount_minor' => 5000, 'currency' => 'USD'], 'payment_token' => 'tok_sim_approved', 'order_reference' => 'A2', 'capture' => false])->json();
    $this->withToken($secret)->withHeader('Idempotency-Key', 'a-void')->postJson('/v1/transactions/'.$auth2['id'].'/void', [])
        ->assertOk()->assertJsonPath('state', 'voided');
    $this->withToken($secret)->withHeader('Idempotency-Key', 'a-cap2')->postJson('/v1/transactions/'.$auth2['id'].'/capture', [])
        ->assertStatus(409)->assertJsonPath('error.code', 'illegal_transition');

    $this->asTenant();
    expect(app(Ledger::class)->isBalanced())->toBeTrue();
});

it('never lets one merchant read another merchant\'s payment', function () {
    $a = $this->handoff();
    $b = $this->handoff();
    $secretA = $this->sandboxSecret($a['id']);
    $secretB = $this->sandboxSecret($b['id']);
    $txn = $this->withToken($secretA)->withHeader('Idempotency-Key', 'iso-1')
        ->postJson('/v1/transactions', ['amount' => ['amount_minor' => 1000, 'currency' => 'USD'], 'payment_token' => 'tok_sim_approved', 'order_reference' => 'I1'])->json();

    $this->withToken($secretB)->getJson('/v1/transactions/'.$txn['id'])->assertNotFound();
    $this->withToken($secretB)->withHeader('Idempotency-Key', 'iso-r')
        ->postJson('/v1/refunds', ['transaction_id' => $txn['id'], 'amount' => ['amount_minor' => 100, 'currency' => 'USD']])->assertNotFound();
    expect($this->withToken($secretB)->getJson('/v1/transactions')->json('data'))->toBe([]);
});

it('keeps live processing closed until the merchant is activated', function () {
    $merchant = $this->handoff();
    $this->asTenant();
    app(ApiKeyIssuing::class)->issuePair(self::TENANT, $merchant['id'], 'live');
    app(TenantScope::class)->clear();
    $live = $this->sandboxSecret($merchant['id'], environment: 'live');
    $this->withToken($live)->withHeader('Idempotency-Key', 'live-1')
        ->postJson('/v1/transactions', ['amount' => ['amount_minor' => 1000, 'currency' => 'USD'], 'payment_token' => 'tok_sim_approved', 'order_reference' => 'L1'])
        ->assertStatus(403)->assertJsonPath('error.code', 'merchant_not_live');
});
