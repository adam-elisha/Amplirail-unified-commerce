<?php

declare(strict_types=1);

namespace Modules\Onboarding\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Contracts\BelongsToTenant;

/**
 * Dynamic requirement added by a processor more-info request (or risk/operations). Reopens the checklist.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $merchant_id
 * @property string $document_type
 * @property string $label
 * @property string|null $reason
 * @property string $source
 * @property string $status
 * @property string|null $satisfied_by_document_id
 * @property Carbon|null $satisfied_at
 * @property Carbon $created_at
 */
final class DocumentRequest extends Model
{
    use BelongsToTenant;

    protected $table = 'document_requests';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['satisfied_at' => 'datetime'];

    public function merchantScopeColumn(): string
    {
        return 'merchant_id';
    }
}
