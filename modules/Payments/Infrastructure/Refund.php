<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $merchant_id
 * @property string $transaction_id
 * @property string $environment
 * @property int $amount_minor
 * @property string $currency
 * @property string $status
 * @property string|null $reason
 * @property string|null $provider_ref
 * @property string $idempotency_key
 * @property Carbon $created_at
 */
final class Refund extends Model
{
    use BelongsToTenant;

    protected $table = 'refunds';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['amount_minor' => 'integer'];

    public function merchantScopeColumn(): string
    {
        return 'merchant_id';
    }

    /** @return array<string,mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'object' => 'refund',
            'transaction_id' => $this->transaction_id,
            'status' => $this->status,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'reason' => $this->reason,
            'created_at' => $this->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
