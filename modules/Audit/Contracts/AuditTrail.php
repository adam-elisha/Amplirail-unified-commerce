<?php

declare(strict_types=1);

namespace Modules\Audit\Contracts;

use App\Support\Actor;

/** Immutable audit trail (SECURITY.md: log auth, admin, money-moving and configuration events immutably). */
interface AuditTrail
{
    /**
     * @param  array<string,mixed>|null  $before
     * @param  array<string,mixed>|null  $after
     */
    public function record(string $action, string $subjectType, string $subjectId, ?array $before, ?array $after, ?Actor $actor = null): void;
}
