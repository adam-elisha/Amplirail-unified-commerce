<?php

declare(strict_types=1);

namespace Modules\Approvals\Contracts;

final class ApprovalView
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $type,
        public readonly string $subjectType,
        public readonly string $subjectId,
        public readonly string $status,
        public readonly array $payload,
        public readonly string $requestedBy,
        public readonly ?string $decidedBy,
        public readonly ?string $reason,
    ) {}
}
