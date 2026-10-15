<?php

declare(strict_types=1);

namespace Modules\Identity\Contracts;

/** Step-up authentication for sensitive actions: authenticator code for admins, emailed code for merchants. */
interface StepUpVerifier
{
    public function verify(string $userId, string $code): bool;
}
