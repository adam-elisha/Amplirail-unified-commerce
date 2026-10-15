<?php

declare(strict_types=1);

namespace Connectors\Secrets;

/**
 * Resolves secrets by reference (SECURITY.md: AWS Secrets Manager and KMS only).
 * Values are never logged, never returned to clients and never written to the database.
 */
interface SecretsResolver
{
    /** @throws SecretUnavailable when the secret cannot be read (fail closed). */
    public function get(string $ref): string;

    /** True only when the secret exists and is readable now. Any error means false. */
    public function exists(string $ref): bool;
}
