<?php
declare(strict_types=1);
namespace AmpliRail\Connectors\Contracts;

use AmpliRail\Money\Money;

/** Canonical request. Carries a provider token only, never a card number (rule 2). */
final class PaymentRequest
{
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $merchantAccountId,   // AmpliRail MID record id
        public readonly Money $amount,
        public readonly string $paymentToken,        // from provider-hosted fields
        public readonly string $orderReference,
        public readonly ?string $providerTransactionId = null, // for capture, refund, void
        public readonly string $initiator = 'customer',        // customer | merchant (stored credential flag)
        public readonly array $metadata = [],
    ) {
        if (preg_match('/\b\d{13,19}\b/', $paymentToken)) {
            throw new \InvalidArgumentException('Payment token looks like a card number; rejected (rule 2).');
        }
    }
}
