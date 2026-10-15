<?php

declare(strict_types=1);

namespace Modules\Onboarding\Contracts;

interface MerchantDirectory
{
    public function profile(string $merchantId): ?MerchantProfile;

    /** Sandbox webhook signing secret provisioned at submitted (arwhk_sbx_...). */
    public function sandboxWebhookSecret(string $merchantId): ?string;

    /** Resolves the tenant of a merchant for public flows (pay pages) before any tenant context exists. */
    public function tenantOf(string $merchantId): ?string;

    /** @return list<string> merchant IDs eligible for statement runs (fee schedule assigned) */
    public function merchantsWithFeeSchedule(): array;
}
