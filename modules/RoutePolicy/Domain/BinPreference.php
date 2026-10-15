<?php

declare(strict_types=1);

namespace Modules\RoutePolicy\Domain;

use AmpliRail\Routing\MerchantAccount;
use Modules\RoutePolicy\Contracts\RouteContext;

/**
 * "Send these cards to these processors first." Every condition left empty matches anything. Used only to ORDER the
 * merchant's own eligible MIDs; it never adds a MID and never reroutes a declined payment.
 */
final class BinPreference
{
    /**
     * @param  list<string>  $preferredConnectors
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $merchantId,
        public readonly ?string $binPrefix,
        public readonly ?string $brand,
        public readonly ?string $cardType,
        public readonly ?string $country,
        public readonly ?bool $commercial,
        public readonly array $preferredConnectors,
        public readonly int $priority,
    ) {}

    /** @param array{card_brand?:string|null, card_type?:string|null, issuer_country?:string|null, commercial?:bool|null} $binInfo */
    public function matches(RouteContext $ctx, array $binInfo): bool
    {
        $brand = $ctx->brand ?? ($binInfo['card_brand'] ?? null);
        $type = $ctx->cardType ?? ($binInfo['card_type'] ?? null);

        return ($this->merchantId === null || $this->merchantId === $ctx->merchantId)
            && ($this->binPrefix === null || ($ctx->bin !== null && str_starts_with($ctx->bin, $this->binPrefix)))
            && ($this->brand === null || ($brand !== null && strcasecmp($brand, $this->brand) === 0))
            && ($this->cardType === null || ($type !== null && strcasecmp($type, $this->cardType) === 0))
            && ($this->country === null || strcasecmp((string) ($binInfo['issuer_country'] ?? ''), $this->country) === 0)
            && ($this->commercial === null || ($binInfo['commercial'] ?? null) === $this->commercial);
    }

    /**
     * Stable re-order: MIDs on preferred connectors first (in the preference's order), the rest after, each group keeping
     * its MID priority order.
     *
     * @param  list<MerchantAccount>  $mids
     * @return list<MerchantAccount>
     */
    public function apply(array $mids): array
    {
        $rank = array_flip($this->preferredConnectors);
        $indexed = [];
        foreach (array_values($mids) as $i => $m) {
            $indexed[] = [$rank[$m->connector] ?? PHP_INT_MAX, $i, $m];
        }
        usort($indexed, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(fn ($x) => $x[2], $indexed);
    }

    /** Most specific wins: lower priority number first, then more conditions set. */
    public function specificity(): int
    {
        return count(array_filter([$this->merchantId, $this->binPrefix, $this->brand, $this->cardType, $this->country, $this->commercial], fn ($v) => $v !== null));
    }
}
