<?php

declare(strict_types=1);

namespace Modules\Onboarding\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Contracts\BelongsToTenant;

/**
 * Append-only record of every accepted onboarding transition (time-in-state analytics for AR-173).
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $merchant_id
 * @property string|null $from_state
 * @property string $to_state
 * @property string $event
 * @property string|null $event_id
 * @property string $actor_type
 * @property string $actor_id
 * @property string|null $reason
 * @property string|null $correlation_id
 * @property Carbon $created_at
 */
final class StateTransition extends Model
{
    use BelongsToTenant;

    protected $table = 'merchant_state_transitions';

    public const UPDATED_AT = null;

    protected $guarded = [];

    public function merchantScopeColumn(): string
    {
        return 'merchant_id';
    }
}
