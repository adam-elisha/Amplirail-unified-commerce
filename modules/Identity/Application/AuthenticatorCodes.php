<?php

declare(strict_types=1);

namespace Modules\Identity\Application;

use Modules\Identity\Domain\Totp;
use Modules\Identity\Infrastructure\User;

/** Admin console second factor (authenticator app). No emailed codes for admins. */
final class AuthenticatorCodes
{
    public function __construct(
        private readonly PasswordSignIn $passwords,
        private readonly AuthEventLogger $events,
    ) {}

    public function verify(User $user, string $code, string $purpose = 'sign_in'): bool
    {
        if ($user->totp_secret === null || $user->totp_confirmed_at === null) {
            return false;
        }
        $step = Totp::verify($user->totp_secret, trim($code), time(), $user->totp_last_step);
        if ($step === null) {
            $this->passwords->registerFailure($user, 'admin', 'authenticator');

            return false;
        }
        $user->forceFill(['totp_last_step' => $step])->save();
        $this->events->log($purpose === 'step_up' ? 'step_up_verified' : 'mfa_verified', 'ok', 'admin', $user, null, 'authenticator');

        return true;
    }

    /** First-time enrollment: the code proves the user scanned the secret. */
    public function confirmEnrollment(User $user, string $secret, string $code): bool
    {
        $step = Totp::verify($secret, trim($code), time());
        if ($step === null) {
            return false;
        }
        $user->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now(), 'totp_last_step' => $step])->save();
        $this->events->log('mfa_enrolled', 'ok', 'admin', $user, null, 'authenticator');

        return true;
    }
}
