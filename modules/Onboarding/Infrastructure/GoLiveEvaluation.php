<?php

declare(strict_types=1);

namespace Modules\Onboarding\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Contracts\BelongsToTenant;

/**
 * Every go-live gate evaluation with the data each check relied on (AR-172 audit requirement).
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $merchant_id
 * @property string $actor_id
 * @property bool $passed
 * @property list<array<string,mixed>> $checks
 * @property Carbon $created_at
 */
final class GoLiveEvaluation extends Model
{
    use BelongsToTenant;

    protected $table = 'go_live_evaluations';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['checks' => 'array', 'passed' => 'boolean'];

    public function merchantScopeColumn(): string
    {
        return 'merchant_id';
    }
}
