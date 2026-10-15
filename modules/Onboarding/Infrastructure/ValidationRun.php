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
 * @property string $status
 * @property array<string,array<string,mixed>> $checks
 * @property string $created_by
 * @property Carbon $created_at
 */
final class ValidationRun extends Model
{
    use BelongsToTenant;

    protected $table = 'validation_runs';

    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['checks' => 'array'];

    public function merchantScopeColumn(): string
    {
        return 'merchant_id';
    }
}
