<?php

declare(strict_types=1);

namespace Modules\RoutePolicy\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Modules\Tenancy\Contracts\BelongsToTenant;

/** Table mid_controls (tenant-scoped). */
final class MidControl extends Model
{
    use BelongsToTenant;

    protected $table = 'mid_controls';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['monthly_cap_minor' => 'integer', 'daily_cap_minor' => 'integer', 'paused' => 'boolean', 'commercial' => 'boolean', 'preferred_connectors' => 'array', 'priority' => 'integer'];
}
