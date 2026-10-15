<?php

declare(strict_types=1);

namespace Connectors\Boarding;

final class BoardingResult
{
    public function __construct(
        public readonly string $status,      // submitted | rejected | error
        public readonly string $reference,
        public readonly string $message,
    ) {}
}
