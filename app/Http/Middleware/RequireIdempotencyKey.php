<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** AGENTS.md rule 6: every mutating API call carries an Idempotency-Key (1-128 safe characters). */
final class RequireIdempotencyKey
{
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $key = $request->headers->get('Idempotency-Key');
            if (! is_string($key) || ! preg_match('/^[A-Za-z0-9_\-:.]{1,128}$/', $key)) {
                throw ApiException::invalid('idempotency_key_required', 'Send an Idempotency-Key header (1-128 letters, digits, - _ : .).');
            }
        }

        return $next($request);
    }
}
