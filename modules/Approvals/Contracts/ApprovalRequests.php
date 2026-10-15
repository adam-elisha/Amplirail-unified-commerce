<?php

declare(strict_types=1);

namespace Modules\Approvals\Contracts;

/** Dual-control requests (master document 12.1 Approvals). Modules open items here; they never self-approve. */
interface ApprovalRequests
{
    /**
     * Opens a pending approval. If one is already pending for the same type and subject it is returned instead.
     *
     * @param  array<string,mixed>  $payload  snapshot the approver sees (e.g. go-live checks)
     */
    public function request(string $type, string $subjectType, string $subjectId, array $payload, string $requestedBy, ?string $reason = null): ApprovalView;

    public function pendingFor(string $type, string $subjectId): ?ApprovalView;

    /** Returns the approval only if it is APPROVED, of $type, for $subjectId, and decided by someone other than the requester. */
    public function verifiedApproval(string $approvalId, string $type, string $subjectId): ?ApprovalView;
}
