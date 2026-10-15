<?php
declare(strict_types=1);
namespace AmpliRail\Routing;

/** A MID boarded for ONE merchant at one provider (rule 15: routing never leaves the merchant's own MIDs). */
final class MerchantAccount
{
    public function __construct(
        public readonly string $id,
        public readonly string $merchantId,
        public readonly string $connector,     // nmi_bridge | gattera_bridge | simulator
        public readonly string $currency,
        public readonly int $priority,         // lower first
        public readonly bool $active = true,
        public readonly int $maxTicketMinor = PHP_INT_MAX,
    ) {}
}
