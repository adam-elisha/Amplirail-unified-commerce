<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Short-lived opaque bearer token issued to a ServiceClient. Stored as SHA-256 hash.
 *
 * @property string $token_hash
 * @property string $service_client_id
 * @property string $tenant_id
 * @property array<int,string> $scopes
 * @property Carbon $expires_at
 */
final class ServiceToken extends Model
{
    protected $table = 'service_tokens';

    protected $primaryKey = 'token_hash';

    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['scopes' => 'array', 'expires_at' => 'datetime'];
}
