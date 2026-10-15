<?php

declare(strict_types=1);

namespace Modules\Ledger\Contracts;

use AmpliRail\Money\Money;

interface Ledger
{
    /**
     * Posts one balanced journal. Must run inside the caller's database transaction (with the outbox event).
     *
     * @param  list<array{0:string,1:Money}>  $lines  [account, signed amount] debit positive, credit negative
     * @return string journal ID
     *
     * @throws \LogicException when the journal does not balance per currency
     */
    public function post(string $sourceType, string $sourceId, string $environment, array $lines): string;

    public function balance(string $account, string $currency, string $environment): int;

    /** True when every journal of the current tenant sums to zero per currency. */
    public function isBalanced(?string $environment = null): bool;

    /** @return list<array{journal_id:string, account:string, amount_minor:int, currency:string, created_at:string}> */
    public function postingsForSource(string $sourceId): array;
}
