<?php

declare(strict_types=1);

namespace Connectors\Secrets;

/** Test resolver. Can simulate an unavailable secrets manager to prove fail-closed behaviour. */
final class ArraySecretsResolver implements SecretsResolver
{
    public bool $unavailable = false;

    /** @param array<string,string> $values */
    public function __construct(private array $values = []) {}

    public function put(string $ref, string $value): void
    {
        $this->values[$ref] = $value;
    }

    public function get(string $ref): string
    {
        if ($this->unavailable) {
            throw SecretUnavailable::for($ref, 'secrets manager unavailable');
        }
        if (! isset($this->values[$ref])) {
            throw SecretUnavailable::for($ref);
        }

        return $this->values[$ref];
    }

    public function exists(string $ref): bool
    {
        return ! $this->unavailable && isset($this->values[$ref]);
    }
}
