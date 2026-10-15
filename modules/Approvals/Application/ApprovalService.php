<?php

declare(strict_types=1);

namespace Modules\Approvals\Application;

use App\Support\Actor;
use App\Support\Ids;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Approvals\Contracts\ApprovalDenied;
use Modules\Approvals\Contracts\ApprovalFailed;
use Modules\Approvals\Contracts\ApprovalHandler;
use Modules\Approvals\Contracts\ApprovalRequests;
use Modules\Approvals\Contracts\ApprovalView;
use Modules\Approvals\Infrastructure\Approval;
use Modules\Audit\Contracts\AuditTrail;
use Modules\Identity\Contracts\AccessControl;
use Modules\Identity\Contracts\StepUpVerifier;
use Modules\Tenancy\Contracts\TenantScope;

/**
 * Dual control enforced in code, not only in the UI (AR-172):
 *  - the requester can never approve their own request;
 *  - the approver must hold the type's permission in the same tenant;
 *  - the approver must pass step-up authentication and enter a note;
 *  - the handler runs in the same transaction as the decision.
 */
final class ApprovalService implements ApprovalRequests
{
    public function __construct(
        private readonly TenantScope $tenants,
        private readonly AccessControl $access,
        private readonly StepUpVerifier $stepUp,
        private readonly AuditTrail $audit,
        private readonly Container $container,
    ) {}

    public function request(string $type, string $subjectType, string $subjectId, array $payload, string $requestedBy, ?string $reason = null): ApprovalView
    {
        if (config("amplirail_roles.approvals.$type") === null) {
            throw new \InvalidArgumentException("Unknown approval type $type");
        }
        if (($existing = $this->pendingFor($type, $subjectId)) !== null) {
            return $existing;
        }

        $approval = Approval::query()->create([
            'id' => Ids::new('apr'),
            'type' => $type,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'status' => 'pending',
            'payload' => $payload,
            'reason' => $reason,
            'requested_by' => $requestedBy,
            'requested_at' => now(),
        ]);
        $this->audit->record('approval.requested', $subjectType, $subjectId, null, ['approval_id' => $approval->id, 'type' => $type]);

        return $approval->toView();
    }

    public function pendingFor(string $type, string $subjectId): ?ApprovalView
    {
        return Approval::query()->where('type', $type)->where('subject_id', $subjectId)->where('status', 'pending')->first()?->toView();
    }

    public function verifiedApproval(string $approvalId, string $type, string $subjectId): ?ApprovalView
    {
        $a = Approval::query()->whereKey($approvalId)->first();
        if ($a === null || $a->status !== 'approved' || $a->type !== $type || $a->subject_id !== $subjectId) {
            return null;
        }
        if ($a->decided_by === null || $a->decided_by === $a->requested_by) {
            return null;
        }

        return $a->toView();
    }

    /** @return ApprovalView the decided approval (status approved or failed) */
    public function approve(string $approvalId, string $approverUserId, string $stepUpCode, string $note): ApprovalView
    {
        $ctx = $this->tenants->current();
        $approval = Approval::query()->whereKey($approvalId)->firstOrFail();
        $this->assertCanDecide($approval, $approverUserId, $note);

        if (! $this->stepUp->verify($approverUserId, $stepUpCode)) {
            $this->deny($approval, $approverUserId, 'step_up_failed', 'Step-up verification failed.');
        }

        $approver = new Actor('user', $approverUserId, $this->access->displayName($approverUserId));

        return DB::transaction(function () use ($approvalId, $approver, $note, $ctx): ApprovalView {
            /** @var Approval $locked */
            $locked = Approval::query()->whereKey($approvalId)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new ApprovalDenied('not_pending', 'This approval was already decided.');
            }
            $locked->forceFill(['status' => 'approved', 'decided_by' => $approver->id, 'decided_at' => now(), 'decision_note' => $note])->save();

            $handler = $this->handlerFor($locked->type);
            try {
                // Savepoint: a failing handler leaves no partial writes behind, only the failed decision record.
                $result = DB::transaction(fn () => $this->tenants->run($ctx->withActor($approver), fn () => $handler->execute($locked->toView(), $approver)));
                $locked->forceFill(['result' => $result])->save();
                $this->audit->record('approval.approved', $locked->subject_type, $locked->subject_id, null,
                    ['approval_id' => $locked->id, 'type' => $locked->type, 'note' => $note, 'result' => $result], $approver);
            } catch (ApprovalFailed $e) {
                $locked->forceFill(['status' => 'failed', 'result' => ['error' => $e->getMessage(), 'details' => $e->details]])->save();
                $this->audit->record('approval.failed', $locked->subject_type, $locked->subject_id, null,
                    ['approval_id' => $locked->id, 'type' => $locked->type, 'error' => $e->getMessage(), 'details' => $e->details], $approver);
            }

            return $locked->toView();
        });
    }

    public function reject(string $approvalId, string $approverUserId, string $note): ApprovalView
    {
        $approval = Approval::query()->whereKey($approvalId)->firstOrFail();
        $this->assertCanDecide($approval, $approverUserId, $note);
        $approval->forceFill(['status' => 'rejected', 'decided_by' => $approverUserId, 'decided_at' => now(), 'decision_note' => $note])->save();
        $this->audit->record('approval.rejected', $approval->subject_type, $approval->subject_id, null,
            ['approval_id' => $approval->id, 'type' => $approval->type, 'note' => $note], new Actor('user', $approverUserId));

        return $approval->toView();
    }

    private function assertCanDecide(Approval $approval, string $approverUserId, string $note): void
    {
        if ($approval->status !== 'pending') {
            $this->deny($approval, $approverUserId, 'not_pending', 'This approval was already decided.');
        }
        if ($approval->requested_by === $approverUserId) {
            $this->deny($approval, $approverUserId, 'self_approval', 'The requester cannot approve their own request.');
        }
        $permission = (string) config("amplirail_roles.approvals.{$approval->type}");
        if (! $this->access->userCan($approverUserId, $permission, $approval->tenant_id)) {
            $this->deny($approval, $approverUserId, 'missing_permission', "Approver needs the $permission permission.");
        }
        if (mb_strlen(trim($note)) < 5) {
            $this->deny($approval, $approverUserId, 'note_required', 'A decision note is required.');
        }
    }

    private function deny(Approval $approval, string $userId, string $code, string $message): never
    {
        Log::warning('approval.denied', ['approval_id' => $approval->id, 'type' => $approval->type, 'user_id' => $userId, 'code' => $code]);
        $this->audit->record('approval.denied', $approval->subject_type, $approval->subject_id, null,
            ['approval_id' => $approval->id, 'code' => $code], new Actor('user', $userId));
        throw new ApprovalDenied($code, $message);
    }

    private function handlerFor(string $type): ApprovalHandler
    {
        foreach ($this->container->tagged('amplirail.approval_handlers') as $handler) {
            if ($handler instanceof ApprovalHandler && $handler->type() === $type) {
                return $handler;
            }
        }
        throw new \RuntimeException("No handler registered for approval type $type");
    }
}
