<?php

declare(strict_types=1);

namespace Modules\Events\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Contracts\BelongsToTenant;

/**
 * @property int $id
 * @property string $event_id
 * @property string $tenant_id
 * @property string $type
 * @property int $version
 * @property string $environment
 * @property string $org_path
 * @property array{type:string,id:string} $actor
 * @property string $correlation_id
 * @property string $subject_type
 * @property string $subject_id
 * @property string|null $merchant_id
 * @property array<string,mixed> $data
 * @property Carbon $occurred_at
 * @property Carbon|null $published_at
 * @property int $attempts
 */
final class OutboxEvent extends Model
{
    use BelongsToTenant;

    protected $table = 'outbox_events';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'actor' => 'array',
        'data' => 'array',
        'occurred_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    /** @return array<string,mixed> */
    public function envelope(): array
    {
        return [
            'id' => $this->event_id,
            'type' => $this->type,
            'version' => $this->version,
            'occurred_at' => $this->occurred_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'environment' => $this->environment,
            'org_path' => $this->org_path,
            'actor' => $this->actor,
            'correlation_id' => $this->correlation_id,
            'policy_version' => null,
            'subject' => ['type' => $this->subject_type, 'id' => $this->subject_id],
            'data' => $this->data,
        ];
    }
}
