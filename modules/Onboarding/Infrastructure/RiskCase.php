<?php

declare(strict_types=1);

namespace Modules\Onboarding\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Contracts\BelongsToTenant;

/**
 * Risk case received through the RiskDecision connector. Advisory only: a human records the decision.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $merchant_id
 * @property string $provider
 * @property string $reference
 * @property int $score
 * @property string $band
 * @property int $confidence
 * @property string $recommendation
 * @property list<array{code:string,description:string,evidence:string}> $signals
 * @property array<string,mixed> $recommended_controls
 * @property bool $gate_complete
 * @property string $status
 * @property string|null $decision
 * @property string|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_reason
 * @property Carbon $created_at
 */
final class RiskCase extends Model
{
    use BelongsToTenant;

    protected $table = 'risk_cases';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['signals' => 'array', 'recommended_controls' => 'array', 'gate_complete' => 'boolean', 'decided_at' => 'datetime'];

    public function merchantScopeColumn(): string
    {
        return 'merchant_id';
    }
}
