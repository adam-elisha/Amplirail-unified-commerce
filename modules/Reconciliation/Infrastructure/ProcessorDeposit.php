<?php

declare(strict_types=1);

namespace Modules\Reconciliation\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $merchant_id
 * @property string $merchant_account_id
 * @property string $mid_label
 * @property string $environment
 * @property string $currency
 * @property Carbon $deposit_date
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property int $expected_minor
 * @property int $received_minor
 * @property int $difference_minor
 * @property string $reference
 * @property string $source
 * @property string $status
 * @property string|null $resolution_note
 * @property string $recorded_by
 * @property string|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property Carbon $created_at
 */
final class ProcessorDeposit extends Model
{
    use BelongsToTenant;

    protected $table = 'processor_deposits';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'expected_minor' => 'integer',
        'received_minor' => 'integer',
        'difference_minor' => 'integer',
        'deposit_date' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
        'resolved_at' => 'datetime',
    ];

    public function merchantScopeColumn(): string
    {
        return 'merchant_id';
    }
}
