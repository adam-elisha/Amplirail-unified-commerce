<?php

declare(strict_types=1);

namespace App\Support\Crypto;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent cast: string or JSON values encrypted with the field key. Usage: 'ein' => EncryptedField::class,
 * 'owners' => EncryptedField::class.':json'.
 *
 * @implements CastsAttributes<mixed, mixed>
 */
final class EncryptedField implements CastsAttributes
{
    public function __construct(private readonly string $mode = 'string') {}

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        $plain = app(FieldEncryption::class)->decrypt((string) $value);

        return $this->mode === 'json' ? json_decode($plain, true, 512, JSON_THROW_ON_ERROR) : $plain;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        $plain = $this->mode === 'json' ? json_encode($value, JSON_THROW_ON_ERROR) : (string) $value;

        return app(FieldEncryption::class)->encrypt($plain);
    }
}
