<?php

declare(strict_types=1);

namespace Modules\Webhooks\Application;

use App\Support\Actor;
use App\Support\ApiException;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Audit\Contracts\AuditTrail;
use Modules\Events\Contracts\EventConsumer;
use Modules\Onboarding\Contracts\MerchantDirectory;
use Modules\Tenancy\Contracts\TenantScope;
use Modules\Webhooks\Contracts\WebhookStatus;
use Modules\Webhooks\Infrastructure\WebhookDelivery;
use Modules\Webhooks\Infrastructure\WebhookEndpoint;
use Modules\Webhooks\Jobs\DeliverWebhook;

/**
 * Merchant webhook endpoints and fan-out from the outbox (master document 11): every subscribed event becomes a
 * delivery row and a queued, signed POST. Consumer is idempotent (one delivery per endpoint and event).
 */
final class WebhookService implements EventConsumer, WebhookStatus
{
    public const SUBSCRIBABLE = [
        'transaction.approved', 'transaction.declined', 'transaction.blocked', 'transaction.failed', 'transaction.unknown',
        'transaction.captured', 'transaction.voided', 'refund.succeeded', 'refund.failed', 'payout_statement.issued',
        'merchant.live', 'webhook.test',
    ];

    public function __construct(
        private readonly TenantScope $tenants,
        private readonly MerchantDirectory $merchants,
        private readonly WebhookUrlPolicy $urls,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  list<string>  $events
     * @return array{endpoint: WebhookEndpoint, secret: string}
     */
    public function create(string $merchantId, string $environment, string $url, array $events, Actor $actor): array
    {
        if (($error = $this->urls->validate($url)) !== null) {
            throw ApiException::invalid('invalid_url', $error);
        }
        $unknown = array_diff($events, self::SUBSCRIBABLE);
        if ($events === [] || $unknown !== []) {
            throw ApiException::invalid('invalid_events', 'Unknown or empty events: '.implode(', ', $unknown));
        }
        // Sandbox endpoints sign with the secret provisioned at submitted; live endpoints get their own.
        $secret = $environment === 'sandbox'
            ? ($this->merchants->sandboxWebhookSecret($merchantId) ?? 'arwhk_sbx_'.Str::random(40))
            : 'arwhk_live_'.Str::random(40);

        $endpoint = WebhookEndpoint::query()->create([
            'id' => Ids::new('whk'), 'merchant_id' => $merchantId, 'environment' => $environment, 'url' => $url,
            'events' => array_values(array_unique($events)), 'secret' => $secret, 'status' => 'enabled',
        ]);
        $this->audit->record('webhook_endpoint.created', 'webhook_endpoint', $endpoint->id, null, ['url' => $url, 'events' => $events], $actor);

        return ['endpoint' => $endpoint, 'secret' => $secret];
    }

    public function rotateSecret(WebhookEndpoint $endpoint, Actor $actor): string
    {
        $secret = ($endpoint->environment === 'live' ? 'arwhk_live_' : 'arwhk_sbx_').Str::random(40);
        $endpoint->forceFill(['secret' => $secret])->save();
        $this->audit->record('webhook_endpoint.secret_rotated', 'webhook_endpoint', $endpoint->id, null, null, $actor);

        return $secret;
    }

    public function enable(WebhookEndpoint $endpoint, Actor $actor): void
    {
        $endpoint->forceFill(['status' => 'enabled', 'disabled_reason' => null])->save();
        $this->audit->record('webhook_endpoint.enabled', 'webhook_endpoint', $endpoint->id, null, null, $actor);
    }

    /** Sends a webhook.test event to one endpoint (validates delivery for the go-live gate). */
    public function sendTest(WebhookEndpoint $endpoint): WebhookDelivery
    {
        $eventId = Ids::new('evt');
        $delivery = $this->createDelivery($endpoint, [
            'id' => $eventId, 'type' => 'webhook.test', 'environment' => $endpoint->environment,
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'data' => ['merchant_id' => $endpoint->merchant_id, 'message' => 'AmpliRail test event'],
        ], '');
        DeliverWebhook::dispatch($this->tenants->current()->tenantId, $delivery->id)->afterCommit();

        return $delivery;
    }

    /** Replays a delivery as a new, audited delivery (master document 11: delivery log with replay button). */
    public function replay(WebhookDelivery $original, Actor $actor): WebhookDelivery
    {
        $endpoint = WebhookEndpoint::query()->whereKey($original->endpoint_id)->firstOrFail();
        $delivery = DB::transaction(function () use ($endpoint, $original, $actor): WebhookDelivery {
            $replayCount = WebhookDelivery::query()->where('replay_of', 'like', $original->id.'%')->count();
            $d = $this->createDelivery($endpoint, $original->payload, $original->id.($replayCount > 0 ? '#'.($replayCount + 1) : ''));
            $this->audit->record('webhook_delivery.replayed', 'webhook_delivery', $original->id, null, ['replay' => $d->id], $actor);

            return $d;
        });
        DeliverWebhook::dispatch($this->tenants->current()->tenantId, $delivery->id)->afterCommit();

        return $delivery;
    }

    // EventConsumer --------------------------------------------------------------------------------------------------

    public function name(): string
    {
        return 'webhook_fanout';
    }

    public function handles(string $eventType): bool
    {
        return in_array($eventType, self::SUBSCRIBABLE, true);
    }

    public function handle(array $envelope): void
    {
        $merchantId = $envelope['data']['merchant_id'] ?? null;
        if (! is_string($merchantId)) {
            return;
        }
        $environment = in_array($envelope['environment'], ['sandbox', 'live'], true) ? $envelope['environment'] : null;
        $endpoints = WebhookEndpoint::query()->where('merchant_id', $merchantId)->where('status', 'enabled')
            ->when($environment !== null, fn ($q) => $q->where('environment', $environment))->get();
        $payload = [
            'id' => $envelope['id'], 'type' => $envelope['type'], 'environment' => $envelope['environment'],
            'occurred_at' => $envelope['occurred_at'], 'data' => $envelope['data'],
        ];
        foreach ($endpoints as $endpoint) {
            if (! in_array($envelope['type'], $endpoint->events, true)) {
                continue;
            }
            $exists = WebhookDelivery::query()->where('endpoint_id', $endpoint->id)->where('event_id', $envelope['id'])->where('replay_of', '')->exists();
            if ($exists) {
                continue;
            }
            $delivery = $this->createDelivery($endpoint, $payload, '');
            DeliverWebhook::dispatch($endpoint->tenant_id, $delivery->id)->afterCommit();
        }
    }

    // WebhookStatus --------------------------------------------------------------------------------------------------

    public function hasSuccessfulDelivery(string $merchantId): bool
    {
        return WebhookDelivery::query()->where('merchant_id', $merchantId)->where('status', 'delivered')->exists();
    }

    public function stats24h(): array
    {
        $rows = WebhookDelivery::query()->where('created_at', '>=', now()->subDay())
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $delivered = (int) ($rows['delivered'] ?? 0);
        $failed = (int) ($rows['failed'] ?? 0) + (int) ($rows['abandoned'] ?? 0);
        $pending = (int) ($rows['pending'] ?? 0);
        $done = $delivered + $failed;

        return ['delivered' => $delivered, 'failed' => $failed, 'pending' => $pending, 'success_rate' => $done > 0 ? round($delivered * 100 / $done, 1) : null];
    }

    /** @param array<string,mixed> $payload */
    private function createDelivery(WebhookEndpoint $endpoint, array $payload, string $replayOf): WebhookDelivery
    {
        return WebhookDelivery::query()->create([
            'id' => Ids::new('whd'),
            'merchant_id' => $endpoint->merchant_id,
            'endpoint_id' => $endpoint->id,
            'event_id' => (string) $payload['id'],
            'event_type' => (string) $payload['type'],
            'payload' => $payload,
            'status' => 'pending',
            'attempts' => 0,
            'next_attempt_at' => now(),
            'replay_of' => $replayOf,
        ]);
    }
}
