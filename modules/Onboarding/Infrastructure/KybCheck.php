<?php

declare(strict_types=1);

namespace Modules\Onboarding\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $merchant_id
 * @property string $provider
 * @property string $reference
 * @property string $status
 * @property list<string> $reasons
 * @property Carbon $created_at
 */
final class KybCheck extends Model
{
    use BelongsToTenant;

    protected $table = 'kyb_checks';

    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['reasons' => 'array'];

    public function merchantScopeColumn(): string
    {
        return 'merchant_id';
    }
}
