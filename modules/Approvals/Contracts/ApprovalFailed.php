<?php

declare(strict_types=1);

namespace Modules\Approvals\Contracts;

use RuntimeException;

final class ApprovalFailed extends RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
