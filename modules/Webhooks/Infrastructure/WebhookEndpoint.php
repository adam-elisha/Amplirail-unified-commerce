<?php

declare(strict_types=1);

namespace Modules\Webhooks\Infrastructure;

use App\Support\Crypto\EncryptedField;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $merchant_id
 * @property string $environment
 * @property string $url
 * @property list<string> $events
 * @property string $secret
 * @property string $status
 * @property string|null $disabled_reason
 * @property Carbon $created_at
 */
final class WebhookEndpoint extends Model
{
    use BelongsToTenant;

    protected $table = 'webhook_endpoints';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $hidden = ['secret'];

    protected $casts = ['events' => 'array', 'secret' => EncryptedField::class];

    public function merchantScopeColumn(): string
    {
        return 'merchant_id';
    }

    /** @return array<string,mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id, 'object' => 'webhook_endpoint', 'environment' => $this->environment, 'url' => $this->url,
            'events' => $this->events, 'status' => $this->status, 'created_at' => $this->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
