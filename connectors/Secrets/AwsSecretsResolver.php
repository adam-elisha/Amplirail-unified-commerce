<?php

declare(strict_types=1);

namespace Connectors\Secrets;

use Aws\SecretsManager\SecretsManagerClient;
use Throwable;

/** Production resolver: AWS Secrets Manager under SECRETS_PREFIX (amplirail/dev, amplirail/prod). Short in-memory cache. */
final class AwsSecretsResolver implements SecretsResolver
{
    /** @var array<string,array{value:string,at:int}> */
    private array $cache = [];

    public function __construct(
        private readonly SecretsManagerClient $client,
        private readonly string $prefix,
        private readonly int $cacheSeconds = 300,
    ) {}

    public function get(string $ref): string
    {
        $hit = $this->cache[$ref] ?? null;
        if ($hit !== null && $hit['at'] > time() - $this->cacheSeconds) {
            return $hit['value'];
        }
        try {
            $result = $this->client->getSecretValue(['SecretId' => rtrim($this->prefix, '/').'/'.ltrim($ref, '/')]);
        } catch (Throwable $e) {
            throw SecretUnavailable::for($ref, 'secrets manager error '.get_class($e));
        }
        $value = $result['SecretString'] ?? null;
        if (! is_string($value) || $value === '') {
            throw SecretUnavailable::for($ref, 'empty secret');
        }
        $this->cache[$ref] = ['value' => $value, 'at' => time()];

        return $value;
    }

    public function exists(string $ref): bool
    {
        try {
            return $this->get($ref) !== '';
        } catch (Throwable) {
            return false;
        }
    }
}
