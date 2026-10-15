<?php

declare(strict_types=1);

namespace Modules\PlatformFees\Contracts;

interface PlatformFees
{
    /**
     * Partner markups for one merchant's statement, from active rules in that currency.
     *
     * @return list<array{partner_key:string, partner_name:string, amount_minor:int}>
     */
    public function forStatement(string $merchantId, string $currency, int $grossMinor, int $approvedCount): array;
}
