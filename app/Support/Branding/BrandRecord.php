<?php

declare(strict_types=1);

namespace App\Support\Branding;

use Illuminate\Database\Eloquent\Model;

/**
 * Platform table (not tenant-scoped): read by host name before anyone signs in.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $partner_type
 * @property string $name
 * @property string $company
 * @property string $mark
 * @property string|null $tagline
 * @property string $primary_color
 * @property string $navy_color
 * @property string|null $logo_data
 * @property string|null $support_email
 * @property string|null $support_phone
 * @property string|null $terms_url
 * @property string|null $privacy_url
 * @property string|null $security_url
 * @property list<string> $hosts
 * @property string $status
 * @property string $created_by
 */
final class BrandRecord extends Model
{
    protected $table = 'brands';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['hosts' => 'array'];
}
