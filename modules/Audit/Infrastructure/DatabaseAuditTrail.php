<?php

declare(strict_types=1);

namespace Modules\Audit\Infrastructure;

use App\Support\Actor;
use App\Support\RequestContext;
use Illuminate\Http\Request;
use Modules\Audit\Contracts\AuditTrail;
use Modules\Tenancy\Contracts\TenantScope;

final class DatabaseAuditTrail implements AuditTrail
{
    public function __construct(
        private readonly TenantScope $tenants,
        private readonly RequestContext $request,
    ) {}

    public function record(string $action, string $subjectType, string $subjectId, ?array $before, ?array $after, ?Actor $actor = null): void
    {
        $ctx = $this->tenants->has() ? $this->tenants->current() : null;
        $actor ??= $ctx->actor ?? Actor::system();
        $ip = app()->bound('request') && app('request') instanceof Request ? app('request')->ip() : null;

        AuditLog::query()->create([
            'tenant_id' => $ctx?->tenantId,
            'action' => $action,
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before' => $before,
            'after' => $after,
            'correlation_id' => $this->request->correlationId(),
            'ip' => $ip,
        ]);
    }
}
