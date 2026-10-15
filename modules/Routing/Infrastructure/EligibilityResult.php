<?php

declare(strict_types=1);

namespace Modules\Routing\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Contracts\BelongsToTenant;

/**
 * Rule outcomes stored on the merchant record (AR-170 acceptance 2).
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $merchant_id
 * @property string $rule_set_id
 * @property int $rule_set_version
 * @property list<array<string,mixed>> $eligible
 * @property list<array<string,mixed>> $ineligible
 * @property Carbon $evaluated_at
 */
final class EligibilityResult extends Model
{
    use BelongsToTenant;

    protected $table = 'eligibility_results';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['eligible' => 'array', 'ineligible' => 'array', 'evaluated_at' => 'datetime'];

    public function merchantScopeColumn(): string
    {
        return 'merchant_id';
    }
}
