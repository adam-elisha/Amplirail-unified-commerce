<?php

declare(strict_types=1);

namespace Modules\RoutePolicy\Contracts;

use AmpliRail\Routing\MerchantAccount;

interface RoutePolicy
{
    /**
     * Removes the merchant's MIDs that are paused or would go over their daily or monthly cap, then orders the rest by
     * the best matching BIN preference (then by MID priority). Input and output are always the same merchant's MIDs.
     *
     * @param  list<MerchantAccount>  $candidates
     * @return list<MerchantAccount>
     */
    public function order(array $candidates, RouteContext $ctx): array;
}
