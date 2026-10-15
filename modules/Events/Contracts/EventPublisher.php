<?php

declare(strict_types=1);

namespace Modules\Events\Contracts;

/** Event bus behind one interface: Redis Streams at launch, managed streaming later (master document 4.5). */
interface EventPublisher
{
    /** @param array<string,mixed> $envelope */
    public function publish(array $envelope): void;
}
