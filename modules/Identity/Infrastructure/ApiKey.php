<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure;

use App\Support\Crypto\EncryptedField;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Merchant API key. Only a SHA-256 hash is stored for lookup. pending_reveal holds the encrypted secret until the
 * merchant reveals it once in the portal; it is then erased (secret shown once).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $merchant_id
 * @property string $type publishable | secret
 * @property string $environment sandbox | live
 * @property string $display_prefix
 * @property string $key_hash
 * @property array<int,string>|null $scopes
 * @property array<int,string>|null $ip_allowlist
 * @property string|null $pending_reveal
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property string|null $created_by
 */
final class ApiKey extends Model
{
    protected $table = 'api_keys';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $hidden = ['key_hash', 'pending_reveal'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'ip_allowlist' => 'array',
            'pending_reveal' => EncryptedField::class,
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
