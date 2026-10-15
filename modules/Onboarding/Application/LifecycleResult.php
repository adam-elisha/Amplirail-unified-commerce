<?php

declare(strict_types=1);

namespace Modules\Onboarding\Application;

final class LifecycleResult
{
    public function __construct(
        public readonly bool $changed,     // false = idempotent replay, nothing written
        public readonly string $from,
        public readonly string $to,
        public readonly ?string $eventId,
    ) {}
}
