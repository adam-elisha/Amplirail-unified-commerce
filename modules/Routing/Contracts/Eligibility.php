<?php

declare(strict_types=1);

namespace Modules\Routing\Contracts;

interface Eligibility
{
    /**
     * Evaluates the merchant against the currently effective published rule set and stores the outcome.
     *
     * @param  array{country:string, region:?string, mcc:string, channels:list<string>, recurring:bool, monthly_volume_minor:int,
     *               max_ticket_minor:int, chargeback_ratio_bps:?int, currency:string, reserve_bps:?int, documents:list<string>}  $profile
     * @return array{rule_set_version:int, eligible:list<array<string,mixed>>, ineligible:list<array<string,mixed>>, evaluated_at:string}|null
     *                                                                                                                                         null when no rule set is published yet
     */
    public function evaluate(string $merchantId, array $profile): ?array;

    /** @return array{rule_set_version:int, eligible:list<array<string,mixed>>, ineligible:list<array<string,mixed>>, evaluated_at:string}|null */
    public function latest(string $merchantId): ?array;

    /** @return array<string,mixed>|null route definition from the current rule set */
    public function route(string $code): ?array;
}
