<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Modules\Identity\Domain\Totp;
use Modules\Identity\Infrastructure\ApiKey;
use Modules\Identity\Infrastructure\AuthEvent;
use Modules\Identity\Infrastructure\User;
use Modules\Identity\Mail\OneTimeCodeMail;
use Modules\MerchantProducts\Infrastructure\PaymentLink;
use Modules\Tenancy\Contracts\TenantScope;

beforeEach(function () {
    $this->seedPlatform();
    Storage::fake('documents');
    Http::fake(['https://merchant.example/*' => Http::response('ok', 200)]);
});

it('signs an admin in with password then authenticator code, enrolling on first sign-in', function () {
    [$user] = $this->adminUser('owner');
    $user->forceFill(['totp_secret' => null, 'totp_confirmed_at' => null])->save();

    $this->post('/admin/login', ['email' => $user->email, 'password' => 'Correct-Horse-9!'])->assertRedirect('/admin/authenticator');
    $this->get('/admin')->assertRedirect('/admin/login');            // not signed in until the second factor
    $this->get('/admin/authenticator')->assertOk()->assertSee('Set up your authenticator');
    $secret = session('totp_setup_secret');
    $this->post('/admin/authenticator', ['code' => Totp::codeAt($secret, intdiv(time(), 30))])->assertRedirect('/admin');
    $this->get('/admin')->assertOk()->assertSee('Activation command center');
    expect(AuthEvent::query()->where('user_id', $user->id)->where('event', 'sign_in')->count())->toBe(1);
});

it('signs a merchant in with an emailed 8-digit code', function () {
    Mail::fake();
    $m = $this->handoff();
    $user = User::query()->where('portal', 'merchant')->firstOrFail();
    $user->forceFill(['password' => 'Merchant-Pass-9!', 'status' => 'active'])->save();

    $this->post('/app/login', ['email' => $user->email, 'password' => 'Merchant-Pass-9!'])->assertRedirect('/app/verify');
    $code = null;
    Mail::assertSent(OneTimeCodeMail::class, function (OneTimeCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return strlen($mail->code) === 8;
    });
    $this->post('/app/verify', ['code' => '00000000'])->assertSessionHas('error');
    $this->post('/app/verify', ['code' => $code])->assertRedirect('/app');
    $this->get('/app')->assertOk()->assertSee('Go-live checklist')->assertSee($m['public_id']);
});

it('locks an account after five failed attempts and logs it', function () {
    [$user] = $this->adminUser('owner');
    for ($i = 0; $i < 5; $i++) {
        $this->post('/admin/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }
    expect($user->refresh()->isLocked())->toBeTrue();
    // Defence in depth: the per-email rate limit also stops the next attempt outright.
    $this->post('/admin/login', ['email' => $user->email, 'password' => 'Correct-Horse-9!'])->assertStatus(429);
    // Once the rate limit window passes, the lock (not the password) still decides.
    $this->app['cache']->store()->flush();
    $this->post('/admin/login', ['email' => $user->email, 'password' => 'Correct-Horse-9!'])->assertSessionHas('error');
    expect($user->refresh()->isLocked())->toBeTrue()
        ->and(AuthEvent::query()->where('user_id', $user->id)->where('event', 'locked')->count())->toBe(1);
});

it('ends an admin session after 15 idle minutes', function () {
    [$user] = $this->adminUser('owner');
    $this->actingAs($user)->withSession(['mfa_passed' => $user->id, 'last_activity' => time() - 16 * 60])
        ->get('/admin')->assertRedirect('/admin/login');
    expect(AuthEvent::query()->where('user_id', $user->id)->where('event', 'session_expired')->count())->toBe(1);
});

it('renders every admin screen for an owner and enforces permissions for others', function () {
    $m = $this->handoff();
    [$owner] = $this->adminUser('owner');
    $this->actingAsPortal($owner);
    foreach (['/admin', '/admin?sample=1', '/admin/approvals', '/admin/merchants', '/admin/merchants/create', '/admin/merchants/'.$m['id'],
        '/admin/transactions', '/admin/statements', '/admin/routing', '/admin/management', '/admin/developers'] as $url) {
        $this->get($url)->assertOk();
    }
    [$support] = $this->adminUser('support_agent');
    $this->actingAsPortal($support)->get('/admin/routing')->assertForbidden();
    $this->actingAsPortal($support)->get('/admin/management')->assertForbidden();
});

it('shows the full Milestone #1 timeline on the admin transaction screen', function () {
    $m = $this->handoff();
    $secret = $this->sandboxSecret($m['id']);
    $txn = $this->withToken($secret)->withHeader('Idempotency-Key', 'ui-1')
        ->postJson('/v1/transactions', ['amount' => ['amount_minor' => 10000, 'currency' => 'USD'], 'payment_token' => 'tok_sim_approved', 'order_reference' => 'UI-1'])->json();
    $this->relay();
    [$owner] = $this->adminUser('owner');
    $this->actingAsPortal($owner)->get('/admin/transactions/'.$txn['id'])
        ->assertOk()->assertSee('transaction.approved')->assertSee('merchant_payable:'.$m['id'])->assertSee('100.00 USD');
    $this->get('/admin/merchants/'.$m['id'])->assertOk()->assertSee('merchant.sandbox_provisioned')->assertSee('application.created');
});

it('keeps merchant portal users inside their own merchant', function () {
    $a = $this->handoff();
    $b = $this->handoff();
    $secretB = $this->sandboxSecret($b['id']);
    $txnB = $this->withToken($secretB)->withHeader('Idempotency-Key', 'ui-b')
        ->postJson('/v1/transactions', ['amount' => ['amount_minor' => 1000, 'currency' => 'USD'], 'payment_token' => 'tok_sim_approved', 'order_reference' => 'B1'])->json();
    $userA = User::query()->whereHas('memberships', fn ($q) => $q->where('merchant_id', $a['id']))->firstOrFail();
    $userA->forceFill(['status' => 'active', 'password' => 'Merchant-Pass-9!'])->save();
    $this->actingAsPortal($userA)->get('/app/transactions/'.$txnB['id'])->assertNotFound();
    $this->actingAsPortal($userA)->get('/admin')->assertRedirect('/admin/login');
});

it('reveals a secret key once in the portal and never again', function () {
    $m = $this->handoff();
    $user = User::query()->whereHas('memberships', fn ($q) => $q->where('merchant_id', $m['id']))->firstOrFail();
    $user->forceFill(['status' => 'active'])->save();
    $this->asTenant();
    $key = ApiKey::query()->where('merchant_id', $m['id'])->where('type', 'secret')->firstOrFail();
    app(TenantScope::class)->clear();
    $this->actingAsPortal($user)->post('/app/developers/keys/'.$key->id.'/reveal')->assertOk()->assertSee('arsec_sbx_');
    $this->actingAsPortal($user)->post('/app/developers/keys/'.$key->id.'/reveal')->assertSessionHas('error');
});

it('takes a payment through a signed payment link and rejects tampering', function () {
    $m = $this->handoff();
    $secret = $this->sandboxSecret($m['id']);
    $link = $this->withToken($secret)->withHeader('Idempotency-Key', 'pl-1')
        ->postJson('/v1/payment_links', ['amount' => ['amount_minor' => 15000, 'currency' => 'USD'], 'reference' => 'INV-1001'])->assertCreated()->json();
    $path = parse_url($link['url'], PHP_URL_PATH);
    app(TenantScope::class)->clear();

    $this->get($path)->assertOk()->assertSee('150.00 USD')->assertHeader('Content-Security-Policy');
    [$payload, $sig] = explode('.', substr($path, 3));
    $forged = rtrim(strtr(base64_encode((string) json_encode(['m' => $m['id'], 'a' => 1, 'c' => 'USD', 'r' => 'INV-1001', 'e' => time() + 3600])), '+/', '-_'), '=').'.'.$sig;
    $this->get('/l/'.$forged)->assertNotFound();

    $this->post($path, ['payment_token' => 'tok_sim_approved', 'card_brand' => 'visa', 'card_last4' => '1111'])->assertOk()->assertSee('Payment received');
    $this->asTenant();
    expect(PaymentLink::query()->firstOrFail()->status)->toBe('paid');
});

it('reports health without revealing configuration', function () {
    $this->get('/health')->assertOk()->assertJson(['status' => 'ok', 'database' => 'ok'])->assertJsonMissingPath('app_key');
});
