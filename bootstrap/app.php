<?php

declare(strict_types=1);

use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\AuthenticateService;
use App\Http\Middleware\PortalSession;
use App\Http\Middleware\RequireIdempotencyKey;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SecurityHeaders;
use App\Support\ApiException;
use App\Support\RequestContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Approvals\Contracts\ApprovalDenied;
use Modules\Onboarding\Domain\IllegalTransition;
use Modules\Payments\Domain\IllegalTransactionTransition;
use Modules\Tenancy\Contracts\MissingTenantContext;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'v1',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignCorrelationId::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\ApplyBrand::class);
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1'));
        $middleware->alias([
            'auth.apikey' => AuthenticateApiKey::class,
            'auth.service' => AuthenticateService::class,
            'idempotent' => RequireIdempotencyKey::class,
            'portal' => PortalSession::class,
            'permission' => RequirePermission::class,
        ]);
        $middleware->redirectGuestsTo(fn (Request $r) => $r->is('admin*') ? route('admin.login') : route('merchant.login'));
        $middleware->encryptCookies();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['password', 'password_confirmation', 'code', 'step_up_code', 'payment_token']);
        $exceptions->dontReport([ApiException::class, ApprovalDenied::class]); // expected outcomes; both are audited/logged where they occur

        $isApi = fn (Request $r): bool => $r->is('v1/*') || $r->is('health');
        $error = function (int $status, string $type, string $code, string $message, array $extra = []) {
            return response()->json(['error' => array_merge([
                'type' => $type, 'code' => $code, 'message' => $message, 'request_id' => app(RequestContext::class)->requestId(),
            ], $extra)], $status);
        };

        $exceptions->render(function (Throwable $e, Request $r) use ($isApi, $error) {
            if (! $isApi($r)) {
                // Portal pages: domain errors become a flash message on the previous page.
                if ($e instanceof ApiException || $e instanceof ApprovalDenied || $e instanceof IllegalTransition || $e instanceof IllegalTransactionTransition || $e instanceof DomainException) {
                    return back()->withInput($r->except(['password', 'code', 'step_up_code']))->with('error', $e->getMessage());
                }

                return null;
            }

            return match (true) {
                $e instanceof ApiException => $error($e->status, $e->type, $e->errorCode, $e->getMessage(),
                    array_filter(['decline_category' => $e->declineCategory, 'details' => $e->details ?: null])),
                $e instanceof ValidationException => $error(422, 'invalid_request_error', 'validation_failed', 'The request is invalid.', ['details' => $e->errors()]),
                $e instanceof AuthenticationException => $error(401, 'authentication_error', 'unauthenticated', 'Authentication required.'),
                $e instanceof MissingTenantContext => $error(403, 'permission_error', 'tenant_context_required', 'No tenant context.'),
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => $error(404, 'invalid_request_error', 'resource_missing', 'Not found.'),
                $e instanceof ApprovalDenied => $error(403, 'permission_error', $e->reasonCode, $e->getMessage()),
                $e instanceof IllegalTransition, $e instanceof IllegalTransactionTransition => $error(409, 'invalid_request_error', 'illegal_transition', $e->getMessage()),
                $e instanceof ThrottleRequestsException => $error(429, 'rate_limit_error', 'too_many_requests', 'Too many requests.'),
                $e instanceof HttpExceptionInterface => $error($e->getStatusCode(), 'api_error', 'http_'.$e->getStatusCode(), $e->getStatusCode() < 500 ? $e->getMessage() : 'Server error.'),
                default => $error(500, 'api_error', 'internal_error', 'Something went wrong. Quote the request_id when contacting support.'),
            };
        });
    })->create();
