<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Application\ServiceAuth;

/** OAuth2 client credentials grant for internal services (RFC 6749 4.4). */
final class ServiceTokenController
{
    public function issue(Request $request, ServiceAuth $auth): JsonResponse
    {
        $id = (string) ($request->getUser() ?? $request->input('client_id', ''));
        $secret = (string) ($request->getPassword() ?? $request->input('client_secret', ''));
        if ($request->input('grant_type') !== 'client_credentials' || $id === '' || $secret === '') {
            throw new ApiException(400, 'invalid_request_error', 'invalid_request', 'Use grant_type=client_credentials with client credentials.');
        }
        $scopes = array_values(array_filter(explode(' ', (string) $request->input('scope', ''))));
        $token = $auth->issueToken($id, $secret, $scopes);
        if ($token === null) {
            throw new ApiException(401, 'authentication_error', 'invalid_client', 'Client authentication failed.');
        }

        return response()->json($token)->header('Cache-Control', 'no-store');
    }
}
