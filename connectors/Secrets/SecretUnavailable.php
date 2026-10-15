<?php

declare(strict_types=1);

namespace Connectors\Secrets;

use RuntimeException;

final class SecretUnavailable extends RuntimeException
{
    public static function for(string $ref, string $why = 'not found'): self
    {
        // The reference is safe to show; the value never is.
        return new self("Secret [$ref] is unavailable: $why.");
    }
}
