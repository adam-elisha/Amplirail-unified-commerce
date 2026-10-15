<?php

declare(strict_types=1);

namespace Modules\Routing\Application;

use App\Support\Ids;
use Modules\Audit\Contracts\AuditTrail;
use Modules\Routing\Contracts\AccountView;
use Modules\Routing\Contracts\MerchantAccounts;
use Modules\Routing\Infrastructure\MerchantAccount;

final class MerchantAccountService implements MerchantAccounts
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function ensureSandboxAccount(string $merchantId, string $currency): AccountView
    {
        $existing = MerchantAccount::query()->where('merchant_id', $merchantId)->where('environment', 'sandbox')
            ->where('connector', 'simulator')->where('currency', $currency)->first();
        if ($existing !== null) {
            return $existing->toView();
        }
        $account = MerchantAccount::query()->create([
            'id' => Ids::new('macc'),
            'merchant_id' => $merchantId,
            'connector' => 'simulator',
            'environment' => 'sandbox',
            'label' => 'TMID-'.Ids::digits(5),
            'currency' => $currency,
            'priority' => 1,
            'status' => 'active',
        ]);
        $this->audit->record('merchant_account.created', 'merchant_account', $account->id, null, ['merchant_id' => $merchantId, 'environment' => 'sandbox']);

        return $account->toView();
    }

    public function recordLiveAccount(string $merchantId, string $connector, string $providerMid, ?string $providerTid, string $currency, ?string $descriptor, ?int $maxTicketMinor): AccountView
    {
        $existing = MerchantAccount::query()->where('merchant_id', $merchantId)->where('environment', 'live')
            ->where('connector', $connector)->where('provider_mid', $providerMid)->where('currency', $currency)->first();
        if ($existing !== null) {
            return $existing->toView();
        }
        $priority = (int) MerchantAccount::query()->where('merchant_id', $merchantId)->where('environment', 'live')->max('priority') + 1;
        $account = MerchantAccount::query()->create([
            'id' => Ids::new('macc'),
            'merchant_id' => $merchantId,
            'connector' => $connector,
            'environment' => 'live',
            'label' => 'MID-'.substr($providerMid, -6),
            'provider_mid' => $providerMid,
            'provider_tid' => $providerTid,
            'currency' => $currency,
            'priority' => $priority,
            'status' => 'pending',
            'max_ticket_minor' => $maxTicketMinor,
            'descriptor' => $descriptor,
        ]);
        $this->audit->record('merchant_account.created', 'merchant_account', $account->id, null,
            ['merchant_id' => $merchantId, 'environment' => 'live', 'connector' => $connector]);

        return $account->toView();
    }

    public function setCredentialRef(string $accountId, string $credentialRef): AccountView
    {
        if (! preg_match('#^[A-Za-z0-9/_\-.]{3,200}$#', $credentialRef)) {
            throw new \InvalidArgumentException('Credential reference must be a secrets-manager name, not a credential.');
        }
        /** @var MerchantAccount $account */
        $account = MerchantAccount::query()->whereKey($accountId)->firstOrFail();
        $before = $account->credential_ref;
        $account->forceFill(['credential_ref' => $credentialRef])->save();
        $this->audit->record('merchant_account.credential_ref_set', 'merchant_account', $account->id, ['credential_ref' => $before], ['credential_ref' => $credentialRef]);

        return $account->toView();
    }

    public function activateLiveAccounts(string $merchantId): int
    {
        $n = MerchantAccount::query()->where('merchant_id', $merchantId)->where('environment', 'live')->where('status', 'pending')
            ->whereNotNull('credential_ref')->update(['status' => 'active', 'updated_at' => now()]);
        $this->audit->record('merchant_account.activated', 'merchant', $merchantId, null, ['count' => $n]);

        return $n;
    }

    public function forMerchant(string $merchantId, ?string $environment = null): array
    {
        return array_values(MerchantAccount::query()->where('merchant_id', $merchantId)
            ->when($environment !== null, fn ($q) => $q->where('environment', $environment))
            ->orderBy('environment')->orderBy('priority')->get()
            ->map(fn (MerchantAccount $a) => $a->toView())->all());
    }

    public function routable(string $merchantId, string $environment): array
    {
        return array_values(MerchantAccount::query()->where('merchant_id', $merchantId)->where('environment', $environment)->where('status', 'active')
            ->orderBy('priority')->get()->map(fn (MerchantAccount $a) => $a->toCore())->all());
    }

    public function find(string $accountId): ?AccountView
    {
        return MerchantAccount::query()->whereKey($accountId)->first()?->toView();
    }
}
