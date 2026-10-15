<?php

declare(strict_types=1);

namespace Modules\Events\Application;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Modules\Events\Contracts\EventConsumer;
use Modules\Events\Contracts\EventPublisher;
use Modules\Events\Infrastructure\OutboxEvent;
use Modules\Tenancy\Contracts\OrgDirectory;
use Modules\Tenancy\Contracts\TenantContext;
use Modules\Tenancy\Contracts\TenantScope;
use Throwable;

/**
 * Publishes committed outbox events exactly once per event and hands them to internal consumers.
 * An event is marked published only after the publisher and every consumer succeeded (in one transaction with the
 * processed_events markers), so a crash means a retry, never a loss. Consumers are idempotent by processed_events.
 */
final class OutboxRelay
{
    private const MAX_ATTEMPTS = 10;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly TenantScope $tenants,
        private readonly OrgDirectory $orgs,
        private readonly EventPublisher $publisher,
        private readonly Container $container,
    ) {}

    /** @return int number of events published across all tenants */
    public function relayAll(int $limitPerTenant = 200): int
    {
        $total = 0;
        foreach ($this->orgs->tenantIds() as $tenantId) {
            $total += $this->tenants->run(TenantContext::system($tenantId), fn () => $this->relayCurrentTenant($limitPerTenant));
        }

        return $total;
    }

    public function relayCurrentTenant(int $limit): int
    {
        $ids = OutboxEvent::query()->whereNull('published_at')->where('attempts', '<', self::MAX_ATTEMPTS)
            ->orderBy('id')->limit($limit)->pluck('id');
        $published = 0;
        foreach ($ids as $id) {
            try {
                $this->db->transaction(function () use ($id, &$published): void {
                    $query = OutboxEvent::query()->whereKey($id)->whereNull('published_at');
                    if ($this->db->connection()->getDriverName() === 'pgsql') {
                        $query->lock('for update skip locked');
                    }
                    $event = $query->first();
                    if ($event === null) {
                        return; // another relay worker took it
                    }
                    $envelope = $event->envelope();
                    $this->publisher->publish($envelope);
                    foreach ($this->consumers() as $consumer) {
                        if ($consumer->handles($event->type) && $this->claim($consumer->name(), $event->event_id)) {
                            $consumer->handle($envelope);
                        }
                    }
                    $event->forceFill(['published_at' => now()])->save();
                    $published++;
                });
            } catch (Throwable $e) {
                OutboxEvent::query()->whereKey($id)->increment('attempts');
                Log::error('outbox.relay_failed', ['outbox_id' => $id, 'error' => get_class($e), 'message' => $e->getMessage()]);
            }
        }

        return $published;
    }

    private function claim(string $consumer, string $eventId): bool
    {
        return $this->db->table('processed_events')->insertOrIgnore([
            'consumer' => $consumer,
            'event_id' => $eventId,
            'processed_at' => now(),
        ]) === 1;
    }

    /** @return iterable<EventConsumer> */
    private function consumers(): iterable
    {
        /** @var iterable<EventConsumer> */
        return $this->container->tagged('amplirail.event_consumers');
    }
}
