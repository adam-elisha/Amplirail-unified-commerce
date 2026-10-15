<?php

declare(strict_types=1);

namespace Modules\Onboarding\Application;

use Illuminate\Support\Str;
use Modules\Identity\Contracts\ApiKeyIssuing;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Routing\Contracts\MerchantAccounts;

/**
 * Day 0 auto-provisioning at submitted (ADR-0004): simulator MerchantAccount, test MID, arpub_sbx_/arsec_sbx_ key pair and
 * an arwhk_sbx_ webhook secret. Every step is idempotent, so a retry never duplicates MIDs, keys or events.
 * Production credentials are never created here.
 */
final class SandboxProvisioner
{
    public function __construct(
        private readonly MerchantAccounts $accounts,
        private readonly ApiKeyIssuing $keys,
        private readonly MerchantLifecycle $lifecycle,
    ) {}

    /** @return array{merchant_account_id:string, test_mid:string, publishable_key:string, secret_key_hint:string} */
    public function provision(Merchant $merchant): array
    {
        $account = $this->accounts->ensureSandboxAccount($merchant->id, $merchant->currency);
        $pair = $this->keys->issuePair($merchant->tenant_id, $merchant->id, 'sandbox');
        if ($merchant->sandbox_webhook_secret === null) {
            $merchant->forceFill(['sandbox_webhook_secret' => 'arwhk_sbx_'.Str::random(40)])->save();
        }
        $summary = [
            'merchant_account_id' => $account->id,
            'test_mid' => $account->label,
            'publishable_key' => $pair->publishableKey,
            'secret_key_hint' => $pair->secretKeyDisplay,
        ];
        $this->lifecycle->apply($merchant, 'merchant.sandbox_provisioned', [
            'merchant_account_id' => $account->id,
            'test_mid' => $account->label,
            'api_key_ids' => [$pair->publishableKeyId, $pair->secretKeyId],
        ]);

        return $summary;
    }
}
