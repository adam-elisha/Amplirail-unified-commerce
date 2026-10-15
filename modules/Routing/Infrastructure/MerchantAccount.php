<?php

declare(strict_types=1);

namespace Modules\Routing\Infrastructure;

use AmpliRail\Routing\MerchantAccount as CoreMerchantAccount;
use Illuminate\Database\Eloquent\Model;
use Modules\Routing\Contracts\AccountView;
use Modules\Tenancy\Contracts\BelongsToTenant;

/**
 * A MID boarded for ONE merchant at one connector and environment (rule 15/21: routing never leaves the merchant's MIDs).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $merchant_id
 * @property string $connector
 * @property string $environment
 * @property string $label
 * @property string|null $provider_mid
 * @property string|null $provider_tid
 * @property string $currency
 * @property int $priority
 * @property string $status
 * @property int|null $max_ticket_minor
 * @property string|null $credential_ref
 * @property string|null $descriptor
 */
final class MerchantAccount extends Model
{
    use BelongsToTenant;

    protected $table = 'merchant_accounts';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['priority' => 'integer', 'max_ticket_minor' => 'integer'];

    public function merchantScopeColumn(): string
    {
        return 'merchant_id';
    }

    public function toCore(): CoreMerchantAccount
    {
        return new CoreMerchantAccount($this->id, $this->merchant_id, $this->connector, $this->currency, $this->priority,
            $this->status === 'active', $this->max_ticket_minor ?? PHP_INT_MAX);
    }

    public function toView(): AccountView
    {
        return new AccountView($this->id, $this->merchant_id, $this->connector, $this->environment, $this->label, $this->provider_mid,
            $this->provider_tid, $this->currency, $this->priority, $this->status, $this->credential_ref, $this->descriptor);
    }
}
