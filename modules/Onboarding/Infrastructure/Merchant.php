<?php

declare(strict_types=1);

namespace Modules\Onboarding\Infrastructure;

use App\Support\Crypto\EncryptedField;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Tenancy\Contracts\BelongsToTenant;

/**
 * The one permanent eDataPay merchant object (4-Day design Section 11).
 * Its state may ONLY change through MerchantLifecycle: a direct save of a changed state throws and is logged (AR-172 abuse case).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $org_node_id
 * @property string $org_path
 * @property string $public_id
 * @property string|null $crm_deal_id
 * @property string $source
 * @property string $merchant_type
 * @property string $state
 * @property string|null $previous_state
 * @property Carbon $state_changed_at
 * @property string $legal_name
 * @property string|null $dba
 * @property string|null $ein
 * @property string|null $ein_fingerprint
 * @property string|null $entity_type
 * @property int|null $years_in_business
 * @property string|null $website
 * @property string|null $phone
 * @property string $timezone
 * @property string|null $business_description
 * @property array<string,string> $address
 * @property array<int,array<string,mixed>>|null $contacts
 * @property array<int,array<string,mixed>>|null $owners
 * @property string|null $bank_token_ref
 * @property string|null $bank_last4
 * @property string $mcc
 * @property string|null $mcc_secondary
 * @property string $country
 * @property string|null $region
 * @property list<string> $channels
 * @property int $monthly_volume_minor
 * @property int $avg_ticket_minor
 * @property int $max_ticket_minor
 * @property string $currency
 * @property int $cnp_percent
 * @property bool $recurring
 * @property int|null $chargeback_ratio_bps
 * @property array<string,mixed>|null $agent
 * @property array<string,mixed>|null $application
 * @property string $kyb_status
 * @property string|null $risk_tier
 * @property array<string,mixed>|null $risk_profile
 * @property string|null $descriptor
 * @property string|null $pci_status
 * @property Carbon|null $pci_expires_at
 * @property string|null $fee_schedule_id
 * @property string|null $payout_method
 * @property string|null $funding_schedule
 * @property Carbon|null $settlement_verified_at
 * @property string|null $sandbox_webhook_secret
 * @property string|null $processor_route
 * @property string|null $processor_reference
 * @property Carbon|null $sandbox_provisioned_at
 * @property Carbon|null $documents_completed_at
 * @property Carbon|null $kyb_passed_at
 * @property Carbon|null $risk_approved_at
 * @property Carbon|null $processor_submitted_at
 * @property Carbon|null $processor_approved_at
 * @property Carbon|null $mid_created_at
 * @property Carbon|null $credentials_created_at
 * @property Carbon|null $test_completed_at
 * @property Carbon|null $live_at
 * @property Carbon $created_at
 */
final class Merchant extends Model
{
    use BelongsToTenant;

    protected $table = 'merchants';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $hidden = ['ein', 'owners', 'contacts', 'sandbox_webhook_secret'];

    private static int $lifecycleDepth = 0;

    protected function casts(): array
    {
        $ts = ['state_changed_at', 'settlement_verified_at', 'sandbox_provisioned_at', 'documents_completed_at', 'kyb_passed_at',
            'risk_approved_at', 'processor_submitted_at', 'processor_approved_at', 'mid_created_at', 'credentials_created_at',
            'test_completed_at', 'live_at'];

        return array_merge(array_fill_keys($ts, 'datetime'), [
            'ein' => EncryptedField::class,
            'contacts' => EncryptedField::class.':json',
            'owners' => EncryptedField::class.':json',
            'sandbox_webhook_secret' => EncryptedField::class,
            'address' => 'array',
            'channels' => 'array',
            'agent' => 'array',
            'application' => 'array',
            'risk_profile' => 'array',
            'recurring' => 'boolean',
            'pci_expires_at' => 'date',
            'monthly_volume_minor' => 'integer',
            'avg_ticket_minor' => 'integer',
            'max_ticket_minor' => 'integer',
        ]);
    }

    public function merchantScopeColumn(): string
    {
        return 'id';
    }

    protected static function booted(): void
    {
        self::updating(function (Merchant $m): void {
            if ($m->isDirty('state') && self::$lifecycleDepth === 0) {
                Log::warning('onboarding.direct_state_write_blocked', ['merchant_id' => $m->id, 'from' => $m->getOriginal('state'), 'to' => $m->state]);
                throw new \LogicException('Merchant state can only change through MerchantLifecycle.');
            }
            if ($m->getOriginal('crm_deal_id') !== null && $m->isDirty('crm_deal_id')) {
                throw new \LogicException('crm_deal_id is immutable.');
            }
            if ($m->getOriginal('agent') !== null && $m->isDirty('agent')) {
                throw new \LogicException('The original agent is locked.');
            }
        });
        self::deleting(fn () => throw new \LogicException('Merchants are never deleted; close them instead.'));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     *
     * @internal only MerchantLifecycle may call this
     */
    public static function asLifecycle(callable $fn): mixed
    {
        self::$lifecycleDepth++;
        try {
            return $fn();
        } finally {
            self::$lifecycleDepth--;
        }
    }

    public function displayName(): string
    {
        return $this->dba !== null && $this->dba !== '' ? $this->dba : $this->legal_name;
    }
}
