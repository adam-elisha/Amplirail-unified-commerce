<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Support\Actor;
use Illuminate\Http\Request;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Tenancy\Contracts\TenantScope;

/**
 * Admin console controllers. Route parameters are plain IDs loaded after the portal middleware has set the tenant
 * context, so every lookup is tenant-scoped (and RLS-checked in PostgreSQL). Business rules live in module services.
 */
abstract class AdminController
{
    protected function actor(): Actor
    {
        return app(TenantScope::class)->current()->actor;
    }

    protected function userId(): string
    {
        return $this->actor()->id;
    }

    protected function merchant(string $id): Merchant
    {
        return Merchant::query()->whereKey($id)->firstOrFail();
    }

    /** @return list<string> */
    protected function permissions(Request $request): array
    {
        /** @var list<string> */
        return (array) $request->attributes->get('permissions', []);
    }

    protected function can(Request $request, string $permission): bool
    {
        return in_array($permission, $this->permissions($request), true);
    }

    protected function requirePermission(Request $request, string $permission): void
    {
        abort_unless($this->can($request, $permission), 403);
    }
}
