<?php

declare(strict_types=1);

namespace Modules\Webhooks\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $merchant_id
 * @property string $endpoint_id
 * @property string $event_id
 * @property string $event_type
 * @property array<string,mixed> $payload
 * @property string $status
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property int|null $last_status_code
 * @property string|null $last_error
 * @property Carbon|null $first_attempt_at
 * @property Carbon|null $delivered_at
 * @property string $replay_of
 * @property Carbon $created_at
 */
final class WebhookDelivery extends Model
{
    use BelongsToTenant;

    protected $table = 'webhook_deliveries';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'next_attempt_at' => 'datetime',
        'first_attempt_at' => 'datetime',
        'delivered_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function merchantScopeColumn(): string
    {
        return 'merchant_id';
    }
}
