<?php

declare(strict_types=1);

namespace Modules\Approvals\Contracts;

use RuntimeException;

/** The approver is not allowed to decide this item (self-approval, missing permission, step-up failed, ...). */
final class ApprovalDenied extends RuntimeException
{
    public function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }
}
