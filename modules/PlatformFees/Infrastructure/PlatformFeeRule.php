<?php

declare(strict_types=1);

namespace Modules\PlatformFees\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Modules\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $merchant_id
 * @property string $partner_key
 * @property string $partner_name
 * @property int $percent_bps
 * @property int $fixed_minor
 * @property string $currency
 * @property string $status
 */
final class PlatformFeeRule extends Model
{
    use BelongsToTenant;

    protected $table = 'platform_fee_rules';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['percent_bps' => 'integer', 'fixed_minor' => 'integer'];

    public function merchantScopeColumn(): string
    {
        return 'merchant_id';
    }
}
