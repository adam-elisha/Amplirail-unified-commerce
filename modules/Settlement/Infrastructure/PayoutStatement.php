<?php

declare(strict_types=1);

namespace Modules\Settlement\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Contracts\BelongsToTenant;

/**
 * Immutable once issued (model guard + database trigger). Corrections are adjustment lines on the next statement.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $merchant_id
 * @property string $merchant_account_id
 * @property string $mid_label
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $currency
 * @property string $fee_schedule_id
 * @property string $fee_schedule_version
 * @property array<string,mixed> $input
 * @property list<array{section:string,label:string,amount_minor:int}> $lines
 * @property int $gross_minor
 * @property int $fees_minor
 * @property int $reserve_minor
 * @property int $net_minor
 * @property int $cb_ratio_bps
 * @property string $status
 * @property string|null $approval_id
 * @property Carbon|null $issued_at
 * @property Carbon $created_at
 */
final class PayoutStatement extends Model
{
    use BelongsToTenant;

    protected $table = 'payout_statements';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'period_start' => 'date', 'period_end' => 'date', 'input' => 'array', 'lines' => 'array', 'issued_at' => 'datetime',
        'gross_minor' => 'integer', 'fees_minor' => 'integer', 'reserve_minor' => 'integer', 'net_minor' => 'integer', 'cb_ratio_bps' => 'integer',
    ];

    public function merchantScopeColumn(): string
    {
        return 'merchant_id';
    }

    protected static function booted(): void
    {
        self::updating(function (PayoutStatement $s): void {
            if ($s->getOriginal('status') === 'issued') {
                throw new \LogicException('Issued payout statements are immutable.');
            }
        });
        self::deleting(function (PayoutStatement $s): void {
            if ($s->status === 'issued') {
                throw new \LogicException('Issued payout statements are immutable.');
            }
        });
    }

    /** @return array<string,mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id, 'object' => 'payout_statement', 'merchant_account' => $this->mid_label,
            'period_start' => $this->period_start->toDateString(), 'period_end' => $this->period_end->toDateString(),
            'currency' => $this->currency, 'status' => $this->status, 'fee_schedule_version' => $this->fee_schedule_version,
            'gross_minor' => $this->gross_minor, 'fees_minor' => $this->fees_minor, 'reserve_minor' => $this->reserve_minor,
            'net_minor' => $this->net_minor, 'chargeback_ratio_bps' => $this->cb_ratio_bps, 'lines' => $this->lines,
            'issued_at' => $this->issued_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
