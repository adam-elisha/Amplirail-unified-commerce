<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A role granted to a user at an org node (inherits downward). merchant_id is set for merchant-portal memberships.
 *
 * @property string $id
 * @property string $user_id
 * @property string $tenant_id
 * @property string $org_node_id
 * @property string $org_path
 * @property string|null $merchant_id
 * @property string $role
 * @property string $status
 * @property Carbon $created_at
 * @property User $user
 */
final class Membership extends Model
{
    protected $table = 'memberships';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return list<string> */
    public function permissions(): array
    {
        /** @var list<string> */
        return config("amplirail_roles.roles.{$this->role}.permissions", []);
    }
}
