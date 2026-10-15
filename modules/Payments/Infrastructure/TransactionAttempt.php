<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $transaction_id
 * @property string $merchant_account_id
 * @property string $connector
 * @property string $operation
 * @property string $outcome
 * @property string|null $response_code
 * @property string|null $message
 * @property bool $retryable
 * @property bool $soft_decline
 * @property int|null $latency_ms
 * @property Carbon $created_at
 */
final class TransactionAttempt extends Model
{
    use BelongsToTenant;

    protected $table = 'transaction_attempts';

    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['retryable' => 'boolean', 'soft_decline' => 'boolean'];
}
