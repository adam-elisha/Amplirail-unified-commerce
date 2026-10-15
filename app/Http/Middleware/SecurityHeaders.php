<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * SECURITY.md application controls: HSTS, CSP with per-request nonces (PCI DSS 6.4.3 on payment pages), nosniff,
 * frame-ancestors none, strict referrer policy. Pay pages additionally allow only the configured hosted-fields script.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(18));
        View::share('cspNonce', $nonce);
        $response = $next($request);

        $isPay = $request->is('l/*');
        $hosted = $isPay ? trim((string) config('connectors.nmi_bridge.collect_js_url')) : '';
        $hostedOrigin = $hosted !== '' ? (parse_url($hosted, PHP_URL_SCHEME).'://'.parse_url($hosted, PHP_URL_HOST)) : '';

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-$nonce'".($hostedOrigin !== '' ? " $hostedOrigin" : ''),
            "style-src 'self' 'nonce-$nonce'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'".($hostedOrigin !== '' ? " $hostedOrigin" : ''),
            $hostedOrigin !== '' ? "frame-src $hostedOrigin" : "frame-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'none'",
            "object-src 'none'",
        ]);

        $headers = $response->headers;
        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $csp);
        }
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(self)');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }
        if ($request->is('admin*', 'app*', 'l/*', 'v1/*')) {
            $headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
