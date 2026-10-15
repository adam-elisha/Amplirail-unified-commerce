<?php

declare(strict_types=1);

namespace Modules\Tenancy\Infrastructure;

use Illuminate\Database\DatabaseManager;
use Modules\Tenancy\Contracts\MissingTenantContext;
use Modules\Tenancy\Contracts\TenantContext;
use Modules\Tenancy\Contracts\TenantScope;

/**
 * Holds the current TenantContext and mirrors it into the PostgreSQL session (app.tenant_id) so RLS policies apply.
 * Bound as a scoped singleton: Octane resets it between requests, and middleware clears the DB setting on terminate.
 */
final class TenantManager implements TenantScope
{
    private ?TenantContext $context = null;

    public function __construct(private readonly DatabaseManager $db) {}

    public function current(): TenantContext
    {
        return $this->context ?? throw new MissingTenantContext;
    }

    public function has(): bool
    {
        return $this->context !== null;
    }

    public function set(TenantContext $context): void
    {
        $this->context = $context;
        $this->syncDatabase($context->tenantId);
    }

    public function clear(): void
    {
        $this->context = null;
        $this->syncDatabase('');
    }

    public function run(TenantContext $context, callable $fn): mixed
    {
        $previous = $this->context;
        $this->set($context);
        try {
            return $fn();
        } finally {
            $previous !== null ? $this->set($previous) : $this->clear();
        }
    }

    private function syncDatabase(string $tenantId): void
    {
        $connection = $this->db->connection();
        if ($connection->getDriverName() === 'pgsql') {
            // Session-level setting; '' never matches a tenant, so a cleared context sees no rows (fail closed).
            $connection->select("select set_config('app.tenant_id', ?, false)", [$tenantId]);
        }
    }
}
