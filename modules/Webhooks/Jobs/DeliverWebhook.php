<?php

declare(strict_types=1);

namespace Modules\Webhooks\Jobs;

use AmpliRail\Webhooks\WebhookSigner;
use App\Support\Actor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Events\Contracts\Outbox;
use Modules\Tenancy\Contracts\TenantContext;
use Modules\Tenancy\Contracts\TenantScope;
use Modules\Webhooks\Application\WebhookUrlPolicy;
use Modules\Webhooks\Infrastructure\WebhookDelivery;
use Modules\Webhooks\Infrastructure\WebhookEndpoint;
use Throwable;

/**
 * One signed delivery attempt. Header AmpliRail-Signature: t=<unix>,v1=<HMAC-SHA256 of "t.body"> (core WebhookSigner).
 * Failures back off per config (up to 72 hours); after that the delivery is abandoned, the endpoint disabled and the
 * owner alerted through the webhook_endpoint.disabled event. Retries are scheduled by amplirail:webhooks:retry.
 */
final class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $tenantId, public readonly string $deliveryId) {}

    public function handle(TenantScope $tenants, Outbox $outbox, WebhookUrlPolicy $urls): void
    {
        $tenants->run(TenantContext::system($this->tenantId, Actor::system('webhook_worker')), function () use ($outbox, $urls): void {
            $delivery = WebhookDelivery::query()->whereKey($this->deliveryId)->first();
            if ($delivery === null || ! in_array($delivery->status, ['pending', 'failed'], true)) {
                return;
            }
            $endpoint = WebhookEndpoint::query()->whereKey($delivery->endpoint_id)->first();
            if ($endpoint === null || $endpoint->status !== 'enabled') {
                $delivery->forceFill(['status' => 'abandoned', 'next_attempt_at' => null, 'last_error' => 'endpoint disabled'])->save();

                return;
            }

            $body = json_encode($delivery->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $ts = time();
            $code = null;
            $error = null;
            try {
                // Re-check at send time: DNS may have changed since the endpoint was registered (rebinding).
                if (($blocked = $urls->validate($endpoint->url)) !== null) {
                    throw new \RuntimeException('Target refused: '.$blocked);
                }
                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'AmpliRail-Webhooks/1.0',
                    'AmpliRail-Signature' => WebhookSigner::sign($endpoint->secret, $body, $ts),
                    'AmpliRail-Event-Id' => $delivery->event_id,
                    'AmpliRail-Delivery-Id' => $delivery->id,
                ])->timeout((int) config('amplirail.webhooks.timeout_seconds'))->withoutRedirecting()
                    ->withBody($body, 'application/json')->post($endpoint->url);
                $code = $response->status();
            } catch (Throwable $e) {
                $error = mb_substr(get_class($e).': '.$e->getMessage(), 0, 250);
            }

            $first = $delivery->first_attempt_at ?? now();
            $attempts = $delivery->attempts + 1;
            if ($code !== null && $code >= 200 && $code < 300) {
                $delivery->forceFill(['status' => 'delivered', 'attempts' => $attempts, 'last_status_code' => $code, 'last_error' => null,
                    'first_attempt_at' => $first, 'delivered_at' => now(), 'next_attempt_at' => null])->save();

                return;
            }

            $schedule = (array) config('amplirail.webhooks.retry_schedule_seconds');
            $delay = $schedule[$attempts - 1] ?? null;
            $expired = $first->copy()->addHours((int) config('amplirail.webhooks.max_age_hours'))->isPast();
            if ($delay === null || $expired) {
                DB::transaction(function () use ($delivery, $endpoint, $attempts, $code, $error, $first, $outbox): void {
                    $delivery->forceFill(['status' => 'abandoned', 'attempts' => $attempts, 'last_status_code' => $code, 'last_error' => $error,
                        'first_attempt_at' => $first, 'next_attempt_at' => null])->save();
                    $endpoint->forceFill(['status' => 'disabled', 'disabled_reason' => 'Deliveries failed for 72 hours'])->save();
                    $outbox->record('webhook_endpoint.disabled', 'webhook_endpoint', $endpoint->id, ['merchant_id' => $endpoint->merchant_id, 'url' => $endpoint->url]);
                });
                Log::warning('webhook.endpoint_disabled', ['endpoint_id' => $endpoint->id, 'merchant_id' => $endpoint->merchant_id]);

                return;
            }
            $delivery->forceFill(['status' => 'failed', 'attempts' => $attempts, 'last_status_code' => $code, 'last_error' => $error,
                'first_attempt_at' => $first, 'next_attempt_at' => now()->addSeconds((int) $delay)])->save();
        });
    }
}
