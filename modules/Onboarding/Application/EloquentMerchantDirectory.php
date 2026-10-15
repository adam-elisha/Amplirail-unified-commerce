<?php

declare(strict_types=1);

namespace Modules\Onboarding\Application;

use Illuminate\Support\Facades\DB;
use Modules\Onboarding\Contracts\MerchantDirectory;
use Modules\Onboarding\Contracts\MerchantProfile;
use Modules\Onboarding\Infrastructure\Merchant;

final class EloquentMerchantDirectory implements MerchantDirectory
{
    public function profile(string $merchantId): ?MerchantProfile
    {
        $m = Merchant::query()->whereKey($merchantId)->first();

        return $m === null ? null : new MerchantProfile(
            $m->id, $m->tenant_id, $m->public_id, $m->org_path, $m->state, $m->legal_name, $m->displayName(), $m->mcc,
            $m->currency, $m->channels, $m->risk_profile ?? [], $m->descriptor, $m->fee_schedule_id, $m->payout_method,
        );
    }

    public function sandboxWebhookSecret(string $merchantId): ?string
    {
        return Merchant::query()->whereKey($merchantId)->first()?->sandbox_webhook_secret;
    }

    public function tenantOf(string $merchantId): ?string
    {
        $tenant = DB::table('merchant_index')->where('merchant_id', $merchantId)->value('tenant_id');

        return is_string($tenant) ? $tenant : null;
    }

    public function merchantsWithFeeSchedule(): array
    {
        /** @var list<string> */
        return Merchant::query()->whereNotNull('fee_schedule_id')->orderBy('id')->pluck('id')->all();
    }
}
