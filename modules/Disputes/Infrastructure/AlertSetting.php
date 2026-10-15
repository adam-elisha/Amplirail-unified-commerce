<?php

declare(strict_types=1);

namespace Modules\Disputes\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Modules\Tenancy\Contracts\BelongsToTenant;

/** Table alert_settings (tenant-scoped; merchant callers see only their own rows). */
final class AlertSetting extends Model
{
    use BelongsToTenant;

    protected $table = 'alert_settings';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['amount_minor' => 'integer', 'max_refund_minor' => 'integer', 'auto_refund' => 'boolean', 'transaction_date' => 'date'];

    public function merchantScopeColumn(): string
    {
        return 'merchant_id';
    }
}
