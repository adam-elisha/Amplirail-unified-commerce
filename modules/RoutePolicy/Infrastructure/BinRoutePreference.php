<?php

declare(strict_types=1);

namespace Modules\RoutePolicy\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Modules\Tenancy\Contracts\BelongsToTenant;

/** Table bin_route_preferences (tenant-scoped). */
final class BinRoutePreference extends Model
{
    use BelongsToTenant;

    protected $table = 'bin_route_preferences';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['monthly_cap_minor' => 'integer', 'daily_cap_minor' => 'integer', 'paused' => 'boolean', 'commercial' => 'boolean', 'preferred_connectors' => 'array', 'priority' => 'integer'];
}
