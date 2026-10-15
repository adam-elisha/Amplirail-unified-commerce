<?php

declare(strict_types=1);

namespace Modules\Disputes\Contracts;

/** What the Disputes module needs to know about the original payment (passed in; Disputes never reads Payments tables). */
final class DisputedTransaction
{
    public function __construct(
        public readonly string $id,
        public readonly string $merchantId,
        public readonly ?string $merchantAccountId,
        public readonly string $environment,
        public readonly string $currency,
        public readonly int $capturedMinor,
        public readonly int $refundedMinor,
    ) {}

    public function disputableMinor(): int
    {
        return max(0, $this->capturedMinor - $this->refundedMinor);
    }
}
