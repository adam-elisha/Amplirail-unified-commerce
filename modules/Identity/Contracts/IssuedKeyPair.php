<?php

declare(strict_types=1);

namespace Modules\Identity\Contracts;

final class IssuedKeyPair
{
    public function __construct(
        public readonly string $publishableKeyId,
        public readonly string $publishableKey,      // not secret
        public readonly string $secretKeyId,
        public readonly string $secretKeyDisplay,    // e.g. arsec_sbx_AbCd...wxyz (never the full value)
        public readonly bool $created,
    ) {}
}
