<?php

declare(strict_types=1);

namespace Modules\Monitoring\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Contracts\BelongsToTenant;

/**
 * Append-only issuer fraud report (Visa TC40 / Mastercard SAFE). Card numbers are never stored.
 *
 * @property string $id
 * @property string $merchant_id
 * @property string|null $merchant_account_id
 * @property string|null $transaction_id
 * @property string $environment
 * @property string $network
 * @property string $report_type
 * @property string|null $fraud_type
 * @property string $network_ref
 * @property Carbon $report_date
 * @property int $amount_minor
 * @property string $currency
 * @property string $source
 */
final class FraudReport extends Model
{
    use BelongsToTenant;

    protected $table = 'network_fraud_reports';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['amount_minor' => 'integer', 'report_date' => 'date'];

    public function merchantScopeColumn(): string
    {
        return 'merchant_id';
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new \LogicException('network_fraud_reports is append-only.'));
        self::deleting(fn () => throw new \LogicException('network_fraud_reports is append-only.'));
    }
}
