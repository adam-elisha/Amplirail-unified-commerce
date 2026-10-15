<?php

declare(strict_types=1);

namespace Modules\Settlement\Infrastructure;

use AmpliRail\Payouts\FeeSchedule as CoreFeeSchedule;
use Illuminate\Database\Eloquent\Model;
use Modules\Tenancy\Contracts\BelongsToTenant;

/**
 * Versioned fee schedule in the core format (packages/amplirail-core/config/test_merchant_fee_schedule.json).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $version
 * @property string $currency
 * @property array<string,mixed> $definition
 * @property string $status
 */
final class FeeSchedule extends Model
{
    use BelongsToTenant;

    protected $table = 'fee_schedules';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['definition' => 'array'];

    public function toCore(): CoreFeeSchedule
    {
        return CoreFeeSchedule::fromArray($this->definition);
    }
}
