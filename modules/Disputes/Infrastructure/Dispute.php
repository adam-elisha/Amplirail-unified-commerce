<?php

declare(strict_types=1);

namespace Modules\Disputes\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Disputes\Domain\DisputeStateMachine;
use Modules\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $merchant_id
 * @property string|null $merchant_account_id
 * @property string $transaction_id
 * @property string $environment
 * @property int $amount_minor
 * @property string $currency
 * @property string $stage
 * @property string $reason_category
 * @property string|null $reason_code
 * @property string $network_case_id
 * @property string $status
 * @property Carbon|null $due_at
 * @property string|null $merchant_response
 * @property array<int, array<string,string>>|null $evidence
 * @property string|null $resolution_note
 * @property string $opened_by
 * @property string|null $resolved_by
 * @property Carbon|null $responded_at
 * @property Carbon|null $resolved_at
 * @property Carbon $created_at
 */
final class Dispute extends Model
{
    use BelongsToTenant;

    protected $table = 'disputes';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'amount_minor' => 'integer',
        'evidence' => 'array',
        'due_at' => 'datetime',
        'responded_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function merchantScopeColumn(): string
    {
        return 'merchant_id';
    }

    public function isOpen(): bool
    {
        return DisputeStateMachine::isOpen($this->status);
    }

    public function categoryLabel(): string
    {
        return DisputeStateMachine::CATEGORIES[$this->reason_category] ?? $this->reason_category;
    }

    public function daysLeft(): ?int
    {
        if ($this->due_at === null || ! $this->isOpen()) {
            return null;
        }

        return (int) floor(now()->diffInHours($this->due_at, false) / 24);
    }

    /** @return array<string,mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'object' => 'dispute',
            'transaction_id' => $this->transaction_id,
            'status' => $this->status,
            'stage' => $this->stage,
            'reason_category' => $this->reason_category,
            'reason_code' => $this->reason_code,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'due_at' => $this->due_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'created_at' => $this->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
