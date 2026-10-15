<?php

declare(strict_types=1);

namespace Modules\RoutePolicy\Contracts;

/** Card and payment facts available before routing. Never a card number: BIN (6-8 digits), brand and type only. */
final class RouteContext
{
    public function __construct(
        public readonly string $merchantId,
        public readonly string $environment,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly ?string $bin = null,
        public readonly ?string $brand = null,
        public readonly ?string $cardType = null,
    ) {}
}
