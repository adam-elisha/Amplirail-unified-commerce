<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Sign-in and session log (master document 5.4). Append-only.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property string|null $user_id
 * @property string $email_masked
 * @property string $portal
 * @property string $event
 * @property string $result
 * @property string|null $method
 * @property string|null $ip
 * @property string|null $user_agent
 * @property bool $new_device
 * @property string|null $correlation_id
 * @property Carbon $created_at
 */
final class AuthEvent extends Model
{
    protected $table = 'auth_events';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['new_device' => 'boolean'];

    protected static function booted(): void
    {
        self::updating(fn () => throw new \LogicException('auth_events is append-only.'));
        self::deleting(fn () => throw new \LogicException('auth_events is append-only.'));
    }
}
