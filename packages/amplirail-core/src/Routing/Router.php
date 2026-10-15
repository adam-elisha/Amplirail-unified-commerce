<?php
declare(strict_types=1);
namespace AmpliRail\Routing;

/** Picks eligible MIDs in priority order. Only the merchant's own active MIDs in the payment currency (rule 15). */
final class Router
{
    /** @param MerchantAccount[] $accounts @return MerchantAccount[] */
    public function candidates(array $accounts, string $merchantId, string $currency, int $amountMinor): array
    {
        $c = array_values(array_filter($accounts, fn(MerchantAccount $a) => $a->merchantId === $merchantId && $a->active && $a->currency === $currency && $amountMinor <= $a->maxTicketMinor));
        usort($c, fn($a, $b) => $a->priority <=> $b->priority);
        return $c;
    }
}
