<?php

declare(strict_types=1);

namespace Modules\Velocity\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Modules\Tenancy\Contracts\BelongsToTenant;
use Modules\Velocity\Domain\VelocityRule;

/**
 * @property string $id
 * @property string $name
 * @property string $scope
 * @property string|null $scope_value
 * @property string $dimension
 * @property string $metric
 * @property int $window_minutes
 * @property int $limit_value
 * @property string|null $currency
 * @property string $environment
 * @property string $action
 * @property string $status
 * @property string $created_by
 */
final class VelocityRuleRecord extends Model
{
    use BelongsToTenant;

    protected $table = 'velocity_rules';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['window_minutes' => 'integer', 'limit_value' => 'integer'];

    public function toDomain(): VelocityRule
    {
        return new VelocityRule($this->id, $this->name, $this->scope, $this->scope_value, $this->dimension, $this->metric,
            $this->window_minutes, $this->limit_value, $this->currency, $this->environment, $this->action);
    }
}
