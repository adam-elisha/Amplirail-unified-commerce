<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** Usage: permission:merchants.view (after portal:...). Denials are logged. */
final class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $permissions = (array) $request->attributes->get('permissions', []);
        if (! in_array($permission, $permissions, true)) {
            Log::warning('authz.denied', ['permission' => $permission, 'path' => $request->path()]);
            abort(403, 'You do not have permission for this page or action.');
        }

        return $next($request);
    }
}
