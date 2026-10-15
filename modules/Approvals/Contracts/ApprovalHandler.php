<?php

declare(strict_types=1);

namespace Modules\Approvals\Contracts;

use App\Support\Actor;

/**
 * Executes an approved item (registered by the owning module with the 'amplirail.approval_handlers' tag).
 * Runs inside the approval's database transaction; throw ApprovalFailed to record a failed outcome.
 */
interface ApprovalHandler
{
    public function type(): string;

    /** @return array<string,mixed> result stored on the approval */
    public function execute(ApprovalView $approval, Actor $approver): array;
}
