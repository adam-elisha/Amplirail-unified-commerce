<?php

declare(strict_types=1);

namespace Modules\Onboarding\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Contracts\BelongsToTenant;

/**
 * KYB evidence metadata. The file itself is encrypted and stored privately under a random name.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $merchant_id
 * @property string $document_type
 * @property string $original_name
 * @property string $mime
 * @property int $size_bytes
 * @property string $sha256
 * @property string $storage_disk
 * @property string $storage_path
 * @property string $status
 * @property string $uploaded_by_type
 * @property string $uploaded_by
 * @property Carbon|null $removed_at
 * @property Carbon $created_at
 */
final class MerchantDocument extends Model
{
    use BelongsToTenant;

    protected $table = 'merchant_documents';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $hidden = ['storage_path', 'storage_disk'];

    protected $casts = ['removed_at' => 'datetime', 'size_bytes' => 'integer'];

    public function merchantScopeColumn(): string
    {
        return 'merchant_id';
    }
}
