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
 * @property string|null $merchant_account_id
 * @property string $environment
 * @property string $idempotency_key
 * @property string $kind
 * @property string $status
 * @property string $state
 * @property int $amount_minor
 * @property string $currency
 * @property int $captured_minor
 * @property int $refunded_minor
 * @property string|null $connector
 * @property string|null $provider_txn_id
 * @property string|null $auth_code
 * @property string|null $response_code
 * @property string|null $decline_category
 * @property string|null $reason
 * @property bool $soft_decline
 * @property string|null $card_brand
 * @property string|null $card_last4
 * @property string|null $card_type
 * @property string|null $entry_mode
 * @property string|null $token_fingerprint
 * @property string $order_reference
 * @property string $initiator
 * @property string $channel
 * @property string|null $payment_link_id
 * @property string|null $correlation_id
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 */
final class Transaction extends Model
{
    use BelongsToTenant;

    protected $table = 'transactions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'amount_minor' => 'integer',
        'captured_minor' => 'integer',
        'refunded_minor' => 'integer',
        'soft_decline' => 'boolean',
    ];

    public function merchantScopeColumn(): string
    {
        return 'merchant_id';
    }

    /**
     * API resource (openapi/launch-v1.yaml Transaction).
     *
     * @return array<string,mixed>
     */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'object' => 'transaction',
            'environment' => $this->environment,
            'kind' => $this->kind,
            'status' => $this->status,
            'state' => $this->state,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'captured_minor' => $this->captured_minor,
            'refunded_minor' => $this->refunded_minor,
            'reason' => $this->reason,
            'response_code' => $this->response_code,
            'decline_category' => $this->decline_category,
            'order_reference' => $this->order_reference,
            'card' => $this->card_last4 !== null ? ['brand' => $this->card_brand, 'last4' => $this->card_last4, 'type' => $this->card_type] : null,
            'merchant_account_id' => $this->merchant_account_id,
            'created_at' => $this->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
