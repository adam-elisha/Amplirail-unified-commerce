<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Tenancy\Contracts\TenantScope;
use Symfony\Component\HttpFoundation\Response;

/** Correlation ID on every request, log line and event; tenant context reset at the start and end of every request. */
final class AssignCorrelationId
{
    public function __construct(private readonly RequestContext $context, private readonly TenantScope $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->tenants->clear();
        $incoming = $request->headers->get('X-Correlation-Id');
        if (is_string($incoming) && $incoming !== '') {
            $this->context->setCorrelationId($incoming);
        }
        Log::shareContext(['correlation_id' => $this->context->correlationId(), 'request_id' => $this->context->requestId()]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $this->context->requestId());
        $response->headers->set('X-Correlation-Id', $this->context->correlationId());

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->tenants->clear();
    }
}
