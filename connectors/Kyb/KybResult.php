<?php

declare(strict_types=1);

namespace Connectors\Kyb;

final class KybResult
{
    /** @param list<string> $reasons */
    public function __construct(
        public readonly string $status,      // passed | review | failed
        public readonly array $reasons,
        public readonly string $provider,
        public readonly string $reference,
    ) {}
}
