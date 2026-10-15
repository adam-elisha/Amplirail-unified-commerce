<?php

declare(strict_types=1);

namespace Modules\Routing\Contracts;

final class AccountView
{
    public function __construct(
        public readonly string $id,
        public readonly string $merchantId,
        public readonly string $connector,
        public readonly string $environment,
        public readonly string $label,
        public readonly ?string $providerMid,
        public readonly ?string $providerTid,
        public readonly string $currency,
        public readonly int $priority,
        public readonly string $status,
        public readonly ?string $credentialRef,
        public readonly ?string $descriptor,
    ) {}
}
