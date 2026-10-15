<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Set-password invitation. Token stored as SHA-256 hash; single use.
 *
 * @property string $id
 * @property string $user_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 */
final class Invitation extends Model
{
    protected $table = 'user_invitations';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['expires_at' => 'datetime', 'accepted_at' => 'datetime'];
}
