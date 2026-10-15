<?php

declare(strict_types=1);

namespace Modules\Reconciliation\Contracts;

/** The MID a deposit belongs to (passed in by the caller; Reconciliation never reads Routing tables). */
final class DepositAccount
{
    public function __construct(
        public readonly string $id,
        public readonly string $merchantId,
        public readonly string $environment,
        public readonly string $currency,
        public readonly string $label,
    ) {}
}
