<?php

declare(strict_types=1);

namespace Modules\Tenancy\Contracts;

/** Org-node operations other modules may use. */
interface OrgDirectory
{
    /** Creates (or returns) the merchant node under the tenant root and returns its materialized path. */
    public function ensureMerchantNode(string $tenantId, string $merchantId, string $name): string;

    /** @return list<string> tenant (organization) IDs, for system jobs that loop over tenants. */
    public function tenantIds(): array;

    public function tenantExists(string $tenantId): bool;
}
