<?php

declare(strict_types=1);

namespace Modules\Identity\Contracts;

interface AccessControl
{
    /** True when the user holds $permission through an active membership in $tenantId. */
    public function userCan(string $userId, string $permission, string $tenantId): bool;

    /** True when the user holds a role with platform.emergency_override (Platform Owner). */
    public function isPlatformOwner(string $userId, string $tenantId): bool;

    public function displayName(string $userId): string;
}
