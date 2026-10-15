<?php

declare(strict_types=1);

namespace Modules\Velocity\Contracts;

/** What a velocity check sees about one payment attempt. Never a card number: the token hash, BIN and brand only. */
final class VelocityInput
{
    public function __construct(
        public readonly string $merchantId,
        public readonly string $environment,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $cardKey,      // sha256 of the payment token
        public readonly ?string $bin = null,  // first 6 to 8 digits, as returned by hosted fields
        public readonly ?string $brand = null,
    ) {}
}
