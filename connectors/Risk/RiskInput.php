<?php

declare(strict_types=1);

namespace Connectors\Risk;

/** Canonical underwriting input. Contains no card data and no raw bank numbers (bank data is a token reference). */
final class RiskInput
{
    /**
     * @param  list<string>  $channels
     * @param  list<string>  $documentIds
     */
    public function __construct(
        public readonly string $merchantId,
        public readonly string $legalName,
        public readonly string $mcc,
        public readonly string $country,
        public readonly int $monthlyVolumeMinor,
        public readonly int $maxTicketMinor,
        public readonly string $currency,
        public readonly array $channels,
        public readonly ?string $website,
        public readonly array $documentIds,
    ) {}
}
