<?php

declare(strict_types=1);

namespace Modules\Identity\Application;

use App\Support\Ids;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Identity\Contracts\ApiPrincipal;
use Modules\Identity\Infrastructure\ServiceClient;
use Modules\Identity\Infrastructure\ServiceToken;

/**
 * Machine authentication for internal services (CRM, RoboRiskAnalysis): OAuth2 client credentials grant issuing
 * short-lived opaque bearer tokens bound to one tenant and a fixed scope list. Portal sessions and merchant API keys
 * can never satisfy a service scope.
 */
final class ServiceAuth
{
    /**
     * @param  list<string>  $scopes
     * @return array{client: ServiceClient, secret: string}
     */
    public function createClient(string $tenantId, string $name, array $scopes): array
    {
        $secret = 'arsvc_'.Str::random(48);
        $client = ServiceClient::query()->create([
            'id' => Ids::new('svc'),
            'tenant_id' => $tenantId,
            'name' => $name,
            'client_id' => 'cli_'.strtolower(Str::random(24)),
            'secret_hash' => Hash::make($secret),
            'scopes' => array_values($scopes),
        ]);

        return ['client' => $client, 'secret' => $secret];
    }

    /**
     * @param  list<string>  $requestedScopes
     * @return array{access_token:string, token_type:string, expires_in:int, scope:string}|null
     */
    public function issueToken(string $clientId, string $clientSecret, array $requestedScopes): ?array
    {
        $client = ServiceClient::query()->where('client_id', $clientId)->whereNull('revoked_at')->first();
        if ($client === null || ! Hash::check($clientSecret, $client->secret_hash)) {
            return null;
        }
        $granted = $requestedScopes === [] ? $client->scopes : array_values(array_intersect($requestedScopes, $client->scopes));
        if ($granted === []) {
            return null;
        }
        $ttl = (int) config('amplirail.auth.service_token_ttl_minutes') * 60;
        $token = 'arsvt_'.Str::random(48);
        ServiceToken::query()->create([
            'token_hash' => hash('sha256', $token),
            'service_client_id' => $client->id,
            'tenant_id' => $client->tenant_id,
            'scopes' => $granted,
            'expires_at' => now()->addSeconds($ttl),
        ]);

        return ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $ttl, 'scope' => implode(' ', $granted)];
    }

    public function authenticate(string $bearer): ?ApiPrincipal
    {
        if (! str_starts_with($bearer, 'arsvt_')) {
            return null;
        }
        $token = ServiceToken::query()->find(hash('sha256', $bearer));
        if ($token === null || $token->expires_at->isPast()) {
            return null;
        }
        $client = ServiceClient::query()->whereKey($token->service_client_id)->whereNull('revoked_at')->first();
        if ($client === null) {
            return null;
        }

        return new ApiPrincipal('service', $client->id, $token->tenant_id, null, null, array_values($token->scopes));
    }
}
