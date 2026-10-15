<?php

declare(strict_types=1);

namespace Modules\Webhooks\Contracts;

interface WebhookStatus
{
    /** True when at least one signed webhook was delivered (2xx) to an endpoint of this merchant. */
    public function hasSuccessfulDelivery(string $merchantId): bool;

    /** @return array{delivered:int, failed:int, pending:int, success_rate:float|null} last 24 hours, current tenant */
    public function stats24h(): array;
}
