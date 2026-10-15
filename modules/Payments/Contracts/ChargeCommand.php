<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

use AmpliRail\Money\Money;

final class ChargeCommand
{
    /**
     * @param  array{brand?:string,last4?:string,type?:string}|null  $card  display data returned by hosted fields (never a PAN)
     * @param  array<string,string>  $metadata  e.g. ['sim' => 'sim_timeout'] for simulator scenarios
     */
    public function __construct(
        public readonly string $merchantId,
        public readonly string $environment,
        public readonly string $idempotencyKey,
        public readonly Money $amount,
        public readonly string $paymentToken,
        public readonly string $orderReference,
        public readonly bool $capture = true,
        public readonly string $channel = 'api',
        public readonly string $initiator = 'customer',
        public readonly ?array $card = null,
        public readonly array $metadata = [],
        public readonly ?string $paymentLinkId = null,
    ) {}

    /** @return array<string,mixed> canonical request used for the idempotency hash */
    public function fingerprint(): array
    {
        return [
            'merchant' => $this->merchantId, 'env' => $this->environment, 'amount' => $this->amount->minor, 'currency' => $this->amount->currency,
            'token' => hash('sha256', $this->paymentToken), 'order' => $this->orderReference, 'capture' => $this->capture,
            'metadata' => $this->metadata,
        ];
    }
}
