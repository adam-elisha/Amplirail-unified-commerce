<?php

declare(strict_types=1);

namespace Modules\Events\Contracts;

/**
 * Internal consumer of relayed events. Consumers must be idempotent; the relay additionally records processed
 * event IDs per consumer so each event is handled once. Register with the 'amplirail.event_consumers' container tag.
 */
interface EventConsumer
{
    public function name(): string;

    public function handles(string $eventType): bool;

    /** @param array<string,mixed> $envelope master document Section 11 envelope */
    public function handle(array $envelope): void;
}
