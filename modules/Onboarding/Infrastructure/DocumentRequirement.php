<?php

declare(strict_types=1);

namespace Modules\Onboarding\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Contracts\BelongsToTenant;

/**
 * Data-driven checklist requirement (AR-169). applies_to keys (all optional, all must match):
 *   mcc_in, mcc_not_in (list), route_in (list of processor routes), channel_any (list), min_monthly_volume_minor (int),
 *   recurring (bool), merchant_type_in (list)
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $document_type
 * @property string $label
 * @property string $stage
 * @property array<string,mixed>|null $applies_to
 * @property bool $active
 * @property int $version
 * @property Carbon $effective_from
 */
final class DocumentRequirement extends Model
{
    use BelongsToTenant;

    protected $table = 'document_requirements';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['applies_to' => 'array', 'active' => 'boolean', 'effective_from' => 'datetime'];
}
