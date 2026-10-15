<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

final class PaymentOutcome
{
    /** @param array<string,mixed> $resource */
    public function __construct(
        public readonly int $httpStatus,
        public readonly array $resource,
        public readonly bool $replayed = false,
    ) {}

    public function status(): string
    {
        return (string) ($this->resource['status'] ?? '');
    }

    public function id(): ?string
    {
        $id = $this->resource['id'] ?? null;

        return is_string($id) ? $id : null;
    }
}
