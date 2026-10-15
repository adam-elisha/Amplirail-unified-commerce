<?php

declare(strict_types=1);

namespace Modules\Approvals\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Approvals\Contracts\ApprovalView;
use Modules\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $type
 * @property string $subject_type
 * @property string $subject_id
 * @property string $status
 * @property array<string,mixed> $payload
 * @property string|null $reason
 * @property string $requested_by
 * @property Carbon $requested_at
 * @property string|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property array<string,mixed>|null $result
 */
final class Approval extends Model
{
    use BelongsToTenant;

    protected $table = 'approvals';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'requested_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function toView(): ApprovalView
    {
        return new ApprovalView($this->id, $this->tenant_id, $this->type, $this->subject_type, $this->subject_id, $this->status,
            $this->payload, $this->requested_by, $this->decided_by, $this->reason);
    }
}
