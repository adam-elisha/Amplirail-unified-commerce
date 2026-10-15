<?php

declare(strict_types=1);

namespace Modules\Routing\Contracts;

use AmpliRail\Routing\MerchantAccount as CoreMerchantAccount;

interface MerchantAccounts
{
    /** Idempotent: returns the existing sandbox simulator MID for the merchant and currency if there is one. */
    public function ensureSandboxAccount(string $merchantId, string $currency): AccountView;

    /** Records a production MID (status pending until go-live activates it). */
    public function recordLiveAccount(string $merchantId, string $connector, string $providerMid, ?string $providerTid, string $currency, ?string $descriptor, ?int $maxTicketMinor): AccountView;

    public function setCredentialRef(string $accountId, string $credentialRef): AccountView;

    /**
     * Activates the merchant's pending live MIDs (called only by the go-live handler).
     *
     * @return int activated
     */
    public function activateLiveAccounts(string $merchantId): int;

    /** @return list<AccountView> */
    public function forMerchant(string $merchantId, ?string $environment = null): array;

    /** @return list<CoreMerchantAccount> active accounts for routing in this environment */
    public function routable(string $merchantId, string $environment): array;

    public function find(string $accountId): ?AccountView;
}
