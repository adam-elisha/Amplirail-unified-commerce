<?php

declare(strict_types=1);

namespace Modules\Settlement\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Contracts\BelongsToTenant;

/**
 * Signed fee agreement for one schedule version (AGENTS.md rule 18: no fee applies before the agreement is signed).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $merchant_id
 * @property string $fee_schedule_id
 * @property string $signer_name
 * @property Carbon $signed_at
 * @property string $document_hash
 * @property string $status
 * @property string $recorded_by
 */
final class FeeAgreement extends Model
{
    use BelongsToTenant;

    protected $table = 'fee_agreements';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['signed_at' => 'datetime'];

    public function merchantScopeColumn(): string
    {
        return 'merchant_id';
    }
}
