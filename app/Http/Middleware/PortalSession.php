<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Actor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Modules\Identity\Application\AuthEventLogger;
use Modules\Identity\Infrastructure\User;
use Modules\Tenancy\Contracts\TenantContext;
use Modules\Tenancy\Contracts\TenantScope;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal access: signed in, correct portal, second factor completed, active membership, idle timeout
 * (admin 15 minutes, PCI DSS 8.2.8). Sets the tenant context from the membership. Usage: portal:admin | portal:merchant
 */
final class PortalSession
{
    public function __construct(private readonly TenantScope $tenants, private readonly AuthEventLogger $events) {}

    public function handle(Request $request, Closure $next, string $portal): Response
    {
        $login = $portal === 'admin' ? 'admin.login' : 'merchant.login';
        /** @var User|null $user */
        $user = Auth::user();
        if ($user === null || $user->portal !== $portal || $user->status !== 'active') {
            return redirect()->route($login);
        }
        if ($request->session()->get('mfa_passed') !== $user->id) {
            return redirect()->route($portal === 'admin' ? 'admin.verify' : 'merchant.verify');
        }
        $idle = (int) config($portal === 'admin' ? 'amplirail.auth.admin_idle_minutes' : 'amplirail.auth.merchant_idle_minutes') * 60;
        $last = (int) $request->session()->get('last_activity', time());
        if (time() - $last > $idle) {
            $this->events->log('session_expired', 'idle_timeout', $portal, $user);
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route($login)->with('status', 'Your session ended after inactivity. Please sign in again.');
        }
        $request->session()->put('last_activity', time());

        $membership = $user->primaryMembership();
        if ($membership === null) {
            Auth::logout();

            return redirect()->route($login);
        }
        $environment = $portal === 'merchant' ? (string) $request->session()->get('environment', 'sandbox') : null;
        $this->tenants->set(new TenantContext($membership->tenant_id, $membership->org_path, $membership->merchant_id,
            new Actor('user', $user->id, $user->name), $environment));

        $permissions = $membership->permissions();
        $request->attributes->set('permissions', $permissions);
        $request->attributes->set('membership', $membership);
        View::share('currentUser', $user);
        View::share('permissions', $permissions);
        View::share('membership', $membership);

        return $next($request);
    }
}
