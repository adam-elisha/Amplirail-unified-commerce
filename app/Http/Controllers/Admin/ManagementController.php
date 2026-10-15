<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Audit\Infrastructure\AuditLog;
use Modules\Identity\Application\UserAdministration;
use Modules\Identity\Infrastructure\AuthEvent;
use Modules\Identity\Infrastructure\Membership;
use Modules\Tenancy\Contracts\TenantScope;
use Modules\Tenancy\Infrastructure\OrgNode;

/** Management: users and roles, sign-in log (master document 5.4) and audit trail. */
final class ManagementController extends AdminController
{
    public function index(Request $request, TenantScope $tenants): View
    {
        $tenantId = $tenants->current()->tenantId;

        return view('admin.management', [
            'members' => Membership::query()->with('user')->where('tenant_id', $tenantId)->whereNull('merchant_id')->orderBy('role')->get(),
            'signins' => AuthEvent::query()->where('tenant_id', $tenantId)->orderByDesc('id')->limit(100)->get(),
            'audit' => AuditLog::query()->where('tenant_id', $tenantId)->orderByDesc('id')->limit(100)->get(),
            'roles' => collect((array) config('amplirail_roles.roles'))->filter(fn ($r) => $r['portal'] === 'admin')->keys(),
            'canInvite' => $this->can($request, 'users.manage'),
        ]);
    }

    public function invite(Request $request, UserAdministration $users, TenantScope $tenants): RedirectResponse
    {
        $d = $request->validate(['email' => ['required', 'email'], 'name' => ['required', 'string', 'max:120'],
            'role' => ['required', 'in:'.collect((array) config('amplirail_roles.roles'))->filter(fn ($r) => $r['portal'] === 'admin')->keys()->implode(',')]]);
        if ($d['role'] === 'platform_owner') {
            return back()->with('error', 'The Platform Owner role is granted on the server with php artisan amplirail:admin:create.');
        }
        $tenantId = $tenants->current()->tenantId;
        $org = OrgNode::query()->whereKey($tenantId)->firstOrFail();
        $users->invite($tenantId, $org->id, $org->path, null, (string) $d['email'], (string) $d['name'], (string) $d['role']);

        return back()->with('status', 'Invitation emailed.');
    }
}
