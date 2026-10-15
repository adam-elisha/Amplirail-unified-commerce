<?php

declare(strict_types=1);

namespace Modules\Routing\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Contracts\BelongsToTenant;

/**
 * Versioned, effective-dated eligibility rules. Published only through Approvals (routing.publish).
 *
 * @property string $id
 * @property string $tenant_id
 * @property int $version
 * @property string $status
 * @property Carbon|null $effective_from
 * @property array{routes: list<array<string,mixed>>} $rules
 * @property string|null $notes
 * @property string $created_by
 * @property string|null $approval_id
 * @property Carbon|null $published_at
 */
final class EligibilityRuleSet extends Model
{
    use BelongsToTenant;

    protected $table = 'eligibility_rule_sets';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['rules' => 'array', 'effective_from' => 'datetime', 'published_at' => 'datetime', 'version' => 'integer'];
}
