<?php

declare(strict_types=1);

namespace Modules\Onboarding\Contracts;

/** Read-only merchant data other modules may use. No PII beyond names. */
final class MerchantProfile
{
    /**
     * @param  array<string,mixed>  $riskProfile
     * @param  list<string>  $channels
     */
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $publicId,
        public readonly string $orgPath,
        public readonly string $state,
        public readonly string $legalName,
        public readonly string $displayName,
        public readonly string $mcc,
        public readonly string $currency,
        public readonly array $channels,
        public readonly array $riskProfile,
        public readonly ?string $descriptor,
        public readonly ?string $feeScheduleId,
        public readonly ?string $payoutMethod,
    ) {}

    public function canProcess(string $environment): bool
    {
        return $environment === 'live'
            ? $this->state === 'active'
            : ! in_array($this->state, ['draft', 'declined', 'suspended', 'closed'], true);
    }
}
