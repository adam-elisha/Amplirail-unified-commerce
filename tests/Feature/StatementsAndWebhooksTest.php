<?php

declare(strict_types=1);

use AmpliRail\Webhooks\WebhookSigner;
use App\Support\Actor;
use App\Support\Logging\MaskSensitiveData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Approvals\Application\ApprovalService;
use Modules\Events\Contracts\Outbox;
use Modules\Events\Infrastructure\OutboxEvent;
use Modules\Ledger\Contracts\Ledger;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Settlement\Application\StatementService;
use Modules\Settlement\Infrastructure\FeeSchedule;
use Modules\Settlement\Infrastructure\PayoutStatement;
use Modules\Tenancy\Contracts\TenantScope;
use Modules\Webhooks\Application\WebhookService;
use Modules\Webhooks\Application\WebhookUrlPolicy;
use Modules\Webhooks\Infrastructure\WebhookDelivery;
use Modules\Webhooks\Infrastructure\WebhookEndpoint;
use Modules\Webhooks\Jobs\DeliverWebhook;

beforeEach(fn () => $this->seedPlatform());

it('builds a payout statement from real transactions that matches the tested core numbers, and issues it with dual control', function () {
    $m = $this->handoff();
    $this->asTenant();
    DB::table('merchants')->where('id', $m['id'])->update(['risk_profile' => json_encode(['max_ticket_minor' => 500000, 'max_per_token_per_hour' => 100])]);
    $schedule = FeeSchedule::query()->firstOrFail();
    DB::table('merchants')->where('id', $m['id'])->update(['fee_schedule_id' => $schedule->id, 'payout_method' => 'ach']);
    app(StatementService::class)->recordAgreement($m['id'], $schedule->id, 'Ana Owner', str_repeat('a', 64), Actor::system('t'));
    app(TenantScope::class)->clear();

    // Test Merchant 01: 11 approved for 1,384.55 and 20 declines (core tests/run.php scenario).
    $secret = $this->sandboxSecret($m['id']);
    $pay = fn (int $minor, string $k) => $this->withToken($secret)->withHeader('Idempotency-Key', $k)
        ->postJson('/v1/transactions', ['amount' => ['amount_minor' => $minor, 'currency' => 'USD'], 'payment_token' => 'tok_sim_approved', 'order_reference' => $k]);
    for ($i = 0; $i < 10; $i++) {
        $pay(12586, "s$i")->assertCreated();
    }
    $pay(12595, 's10')->assertCreated();
    for ($i = 0; $i < 20; $i++) {
        $pay(1002, "d$i")->assertCreated();
    }

    $this->asTenant();
    $today = CarbonImmutable::now('UTC');
    $result = app(StatementService::class)->generate($today->startOfMonth(), $today, 'sandbox');
    expect($result['created'])->toHaveCount(1);
    $s = PayoutStatement::query()->firstOrFail();
    expect($s->gross_minor)->toBe(138455)->and($s->fees_minor)->toBe(15924)->and($s->reserve_minor)->toBe(6923)->and($s->net_minor)->toBe(115608)
        ->and($s->input['declinedCount'])->toBe(20);
    expect(app(StatementService::class)->csv($s))->toContain('"Net payout",1156.08,USD');

    [$finance] = $this->adminUser('finance');
    [$approver, $totp] = $this->adminUser('owner');
    $this->asTenant();
    $approval = app(StatementService::class)->requestIssue($s, $finance->id);
    app(ApprovalService::class)->approve($approval->id, $approver->id, $this->totp($totp), 'Checked against PSP report');
    expect($s->refresh()->status)->toBe('issued')
        ->and(app(Ledger::class)->isBalanced())->toBeTrue()
        ->and(app(Ledger::class)->balance('reserve:'.$m['id'], 'USD', 'sandbox'))->toBe(-6923)
        ->and(OutboxEvent::query()->where('type', 'payout_statement.issued')->count())->toBe(1);
    expect(fn () => $s->refresh()->forceFill(['net_minor' => 1])->save())->toThrow(LogicException::class);

    // Merchant downloads in every format.
    app(TenantScope::class)->clear();
    $this->withToken($secret)->get('/v1/payout_statements/'.$s->id.'?format=csv')->assertOk()->assertSee('Net payout');
    expect($this->withToken($secret)->get('/v1/payout_statements/'.$s->id.'?format=xlsx')->getContent())->toStartWith('PK');
    expect($this->withToken($secret)->get('/v1/payout_statements/'.$s->id.'?format=pdf')->getContent())->toStartWith('%PDF');
});

it('skips merchants without a signed agreement for their fee schedule', function () {
    $m = $this->handoff();
    $this->asTenant();
    DB::table('merchants')->where('id', $m['id'])->update(['fee_schedule_id' => FeeSchedule::query()->value('id')]);
    $result = app(StatementService::class)->generate(CarbonImmutable::now()->startOfWeek(), CarbonImmutable::now(), 'sandbox');
    expect($result['created'])->toBe([])->and($result['skipped'][0]['reason'])->toBe('agreement_not_signed');
});

it('retries failed webhooks with backoff, then disables the endpoint after 72 hours and alerts', function () {
    Http::fake(['https://down.example/*' => Http::response('nope', 500)]);
    $m = $this->handoff();
    $this->asTenant();
    $created = app(WebhookService::class)->create($m['id'], 'sandbox', 'https://down.example/h', ['webhook.test'], Actor::system('t'));
    $delivery = app(WebhookService::class)->sendTest($created['endpoint']);
    $delivery->refresh();
    expect($delivery->status)->toBe('failed')->and($delivery->attempts)->toBe(1)->and($delivery->next_attempt_at->isFuture())->toBeTrue();

    // 73 hours later the next attempt abandons and disables.
    DB::table('webhook_deliveries')->where('id', $delivery->id)->update(['first_attempt_at' => now()->subHours(73), 'next_attempt_at' => now()->subMinute()]);
    (new DeliverWebhook(self::TENANT, $delivery->id))->handle(app(TenantScope::class), app(Outbox::class), app(WebhookUrlPolicy::class));
    $this->asTenant();
    expect(WebhookDelivery::query()->find($delivery->id)->status)->toBe('abandoned')
        ->and(WebhookEndpoint::query()->find($created['endpoint']->id)->status)->toBe('disabled')
        ->and(OutboxEvent::query()->where('type', 'webhook_endpoint.disabled')->count())->toBe(1);
});

it('replays a delivery as a new audited delivery with a valid signature', function () {
    Http::fake(['https://ok.example/*' => Http::response('ok', 200)]);
    $m = $this->handoff();
    $this->asTenant();
    $created = app(WebhookService::class)->create($m['id'], 'sandbox', 'https://ok.example/h', ['webhook.test'], Actor::system('t'));
    $first = app(WebhookService::class)->sendTest($created['endpoint']);
    $this->asTenant();
    $replay = app(WebhookService::class)->replay($first->refresh(), Actor::system('t'));
    $this->asTenant();
    expect($replay->refresh()->status)->toBe('delivered')->and($replay->replay_of)->toBe($first->id)
        ->and(DB::table('audit_log')->where('action', 'webhook_delivery.replayed')->count())->toBe(1);
    Http::assertSent(fn ($r) => WebhookSigner::verify($created['secret'], $r->body(), $r->header('AmpliRail-Signature')[0], time()));
});

it('writes no secrets or card data to the logs', function () {
    $path = storage_path('logs/test-scan.log');
    @unlink($path);
    config(['logging.channels.scan' => ['driver' => 'single', 'path' => $path, 'level' => 'debug', 'tap' => [MaskSensitiveData::class]]]);
    Log::setDefaultDriver('scan');
    Http::fake();

    $m = $this->handoff();
    $secret = $this->sandboxSecret($m['id']);
    $this->withToken($secret)->withHeader('Idempotency-Key', 'log-1')
        ->postJson('/v1/transactions', ['amount' => ['amount_minor' => 1000, 'currency' => 'USD'], 'payment_token' => '4111111111111111', 'order_reference' => 'L']);
    Log::warning('debug dump', ['authorization' => 'Bearer '.$secret, 'note' => "card 4111 1111 1111 1111 key $secret", 'email' => 'ana@coastalgrill.example']);
    $this->relay();

    $log = (string) @file_get_contents($path);
    expect($log)->not->toContain($secret)->not->toContain('4111111111111111')->not->toContain('4111 1111 1111 1111')
        ->not->toContain('ana@coastalgrill.example')->not->toContain('12-3456789');
    @unlink($path);
});
