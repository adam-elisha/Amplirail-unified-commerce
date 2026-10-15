<?php

declare(strict_types=1);

namespace Modules\Identity\Application;

use App\Support\RequestContext;
use App\Support\SensitiveData;
use Illuminate\Http\Request;
use Modules\Identity\Infrastructure\AuthEvent;
use Modules\Identity\Infrastructure\User;

/**
 * Writes the sign-in and session log (master document 5.4): user, portal, IP, user agent, method, result, correlation ID.
 * Events: sign_in, sign_out, failed, locked, mfa_challenged, mfa_verified, mfa_failed, password_reset, new_device, session_expired.
 */
final class AuthEventLogger
{
    public function __construct(private readonly RequestContext $context) {}

    public function log(string $event, string $result, string $portal, ?User $user, ?string $email = null, ?string $method = null, bool $newDevice = false): void
    {
        $request = app()->bound('request') ? app('request') : null;
        $membership = $user?->primaryMembership();

        AuthEvent::query()->create([
            'tenant_id' => $membership?->tenant_id,
            'user_id' => $user?->id,
            'email_masked' => SensitiveData::mask((string) ($email ?? $user->email ?? '')),
            'portal' => $portal,
            'event' => $event,
            'result' => $result,
            'method' => $method,
            'ip' => $request instanceof Request ? $request->ip() : null,
            'user_agent' => $request instanceof Request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'new_device' => $newDevice,
            'correlation_id' => $this->context->correlationId(),
        ]);
    }
}
