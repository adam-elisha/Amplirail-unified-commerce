<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Internal service caller (CRM, RoboRiskAnalysis) using OAuth2 client credentials (master document 13).
 * The secret is stored as an Argon2id hash only.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $client_id
 * @property string $secret_hash
 * @property array<int,string> $scopes
 * @property Carbon|null $revoked_at
 */
final class ServiceClient extends Model
{
    protected $table = 'service_clients';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $hidden = ['secret_hash'];

    protected $casts = ['scopes' => 'array', 'revoked_at' => 'datetime'];
}
