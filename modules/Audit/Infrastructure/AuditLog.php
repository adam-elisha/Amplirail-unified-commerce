<?php

declare(strict_types=1);

namespace Modules\Audit\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Append-only. The database blocks UPDATE and DELETE with a trigger (PostgreSQL) and the app role has no such grants.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property string $action
 * @property string $actor_type
 * @property string $actor_id
 * @property string $subject_type
 * @property string $subject_id
 * @property array<string,mixed>|null $before
 * @property array<string,mixed>|null $after
 * @property string|null $correlation_id
 * @property string|null $ip
 * @property Carbon $created_at
 */
final class AuditLog extends Model
{
    protected $table = 'audit_log';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['before' => 'array', 'after' => 'array'];

    protected static function booted(): void
    {
        self::updating(fn () => throw new \LogicException('audit_log is append-only.'));
        self::deleting(fn () => throw new \LogicException('audit_log is append-only.'));
    }
}
