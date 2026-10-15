<?php

declare(strict_types=1);

namespace Modules\Tenancy\Infrastructure;

use Illuminate\Database\Eloquent\Model;

/**
 * Payments hierarchy node: platform -> organization (tenant) -> iso -> agent -> merchant -> location.
 * Visibility is a path-prefix match on the materialized path.
 *
 * @property string $id
 * @property string $type
 * @property string|null $parent_id
 * @property string $tenant_id
 * @property string $path
 * @property string $name
 * @property string $status
 */
final class OrgNode extends Model
{
    protected $table = 'org_nodes';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];
}
