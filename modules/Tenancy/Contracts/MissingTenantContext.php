<?php

declare(strict_types=1);

namespace Modules\Tenancy\Contracts;

use RuntimeException;

/** Thrown whenever tenant-scoped code runs without a TenantContext (AGENTS.md rule 8: fail closed). */
final class MissingTenantContext extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No tenant context: tenant-scoped data cannot be read or written.');
    }
}
