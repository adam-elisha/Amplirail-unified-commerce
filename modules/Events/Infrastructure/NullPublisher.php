<?php

declare(strict_types=1);

namespace Modules\Events\Infrastructure;

use Modules\Events\Contracts\EventPublisher;

/** Tests: keeps published envelopes in memory. */
final class NullPublisher implements EventPublisher
{
    /** @var list<array<string,mixed>> */
    public array $published = [];

    public function publish(array $envelope): void
    {
        $this->published[] = $envelope;
    }
}
