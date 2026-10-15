<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Actor;
use App\Support\ApiException;
use Closure;
use Illuminate\Http\Request;
use Modules\Identity\Application\ServiceAuth;
use Modules\Identity\Contracts\ApiPrincipal;
use Modules\Tenancy\Contracts\TenantContext;
use Modules\Tenancy\Contracts\TenantScope;
use Symfony\Component\HttpFoundation\Response;

/**
 * Internal service callers (CRM, RoboRiskAnalysis): OAuth2 client-credentials bearer token with a required scope.
 * Merchant API keys and portal sessions can never pass this middleware. Usage: auth.service:crm.handoff
 */
final class AuthenticateService
{
    public function __construct(private readonly ServiceAuth $auth, private readonly TenantScope $tenants) {}

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $principal = $this->auth->authenticate((string) $request->bearerToken());
        if ($principal === null) {
            throw new ApiException(401, 'authentication_error', 'invalid_service_token', 'A valid service access token is required.');
        }
        if (! $principal->hasScope($scope)) {
            throw new ApiException(403, 'permission_error', 'insufficient_scope', "This token lacks the $scope scope.");
        }
        $this->tenants->set(new TenantContext($principal->tenantId, '/'.$principal->tenantId.'/', null, new Actor('service', $principal->id)));
        $request->attributes->set(ApiPrincipal::class, $principal);

        return $next($request);
    }
}
