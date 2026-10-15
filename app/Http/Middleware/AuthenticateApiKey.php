<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Actor;
use App\Support\ApiException;
use Closure;
use Illuminate\Http\Request;
use Modules\Identity\Application\ApiKeyService;
use Modules\Identity\Contracts\ApiPrincipal;
use Modules\Onboarding\Contracts\MerchantDirectory;
use Modules\Tenancy\Contracts\TenantContext;
use Modules\Tenancy\Contracts\TenantScope;
use Symfony\Component\HttpFoundation\Response;

/** Merchant secret key (arsec_sbx_ / arsec_live_). Sets a merchant-scoped tenant context; service tokens are refused. */
final class AuthenticateApiKey
{
    public function __construct(
        private readonly ApiKeyService $keys,
        private readonly TenantScope $tenants,
        private readonly MerchantDirectory $merchants,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = (string) $request->bearerToken();
        $principal = $bearer !== '' ? $this->keys->authenticate($bearer, $request->ip()) : null;
        if ($principal === null || $principal->merchantId === null) {
            throw new ApiException(401, 'authentication_error', 'invalid_api_key', 'A valid secret API key is required.');
        }
        $orgPath = '/'.$principal->tenantId.'/'.$principal->merchantId.'/';
        $this->tenants->set(new TenantContext($principal->tenantId, $orgPath, $principal->merchantId,
            new Actor('api_key', $principal->id), $principal->environment));
        $profile = $this->merchants->profile($principal->merchantId);
        if ($profile !== null) {
            $this->tenants->set(new TenantContext($principal->tenantId, $profile->orgPath, $principal->merchantId,
                new Actor('api_key', $principal->id), $principal->environment));
        }
        $request->attributes->set(ApiPrincipal::class, $principal);

        return $next($request);
    }
}
