<?php

declare(strict_types=1);

namespace Modules\Tenancy\Contracts;

use App\Support\Actor;

/**
 * The caller's tenant scope (master document 5.2). Every tenant-scoped query requires one; without it code fails closed.
 * tenantId    the admin organization node (row-level security key)
 * orgPath     materialized path of the node the caller acts at, e.g. /org_x/ or /org_x/mer_y/
 * merchantId  set for merchant-portal users and API keys: restricts reads to that merchant
 */
final class TenantContext
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $orgPath,
        public readonly ?string $merchantId,
        public readonly Actor $actor,
        public readonly ?string $environment = null,   // sandbox | live (API keys)
    ) {}

    public static function system(string $tenantId, ?Actor $actor = null): self
    {
        return new self($tenantId, '/'.$tenantId.'/', null, $actor ?? Actor::system());
    }

    public function withActor(Actor $actor): self
    {
        return new self($this->tenantId, $this->orgPath, $this->merchantId, $actor, $this->environment);
    }
}
