<?php

declare(strict_types=1);

namespace Modules\RoutePolicy\Infrastructure;

use Illuminate\Database\Eloquent\Model;

/** BIN reference data (shared, no tenant): issuer facts by card prefix. Never card numbers. */
final class BinRange extends Model
{
    protected $table = 'bin_ranges';

    protected $primaryKey = 'bin_prefix';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['commercial' => 'boolean'];
}
