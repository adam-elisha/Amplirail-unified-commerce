<?php

declare(strict_types=1);

namespace Modules\Identity\Application;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Modules\Identity\Infrastructure\User;
use Modules\Identity\Mail\AccountLockedMail;

/**
 * First sign-in factor. Same answer for unknown email and wrong password (no account enumeration).
 * Five failures lock the account for 30 minutes and alert the user (master document 5.1.1).
 */
final class PasswordSignIn
{
    public const OK = 'ok';

    public const INVALID = 'invalid';

    public const LOCKED = 'locked';

    private static ?string $timingHash = null;

    public function __construct(private readonly AuthEventLogger $events) {}

    /** @return array{0:string,1:?User} */
    public function attempt(string $portal, string $email, string $password): array
    {
        $user = User::query()->where('email', mb_strtolower(trim($email)))->first();

        if ($user === null || $user->portal !== $portal || $user->status !== 'active' || $user->password === null) {
            Hash::check($password, self::$timingHash ??= Hash::make(bin2hex(random_bytes(16)))); // equalize timing
            $this->events->log('failed', 'invalid_credentials', $portal, null, $email, 'password');

            return [self::INVALID, null];
        }
        if ($user->isLocked()) {
            $this->events->log('failed', 'locked', $portal, $user, null, 'password');

            return [self::LOCKED, $user];
        }
        if (! Hash::check($password, $user->password)) {
            $this->registerFailure($user, $portal, 'password');

            return [$user->isLocked() ? self::LOCKED : self::INVALID, $user];
        }

        return [self::OK, $user];
    }

    public function registerFailure(User $user, string $portal, string $method): void
    {
        $user->failed_attempts++;
        if ($user->failed_attempts >= (int) config('amplirail.auth.max_failed_attempts')) {
            $user->locked_until = now()->addMinutes((int) config('amplirail.auth.lockout_minutes'));
            $user->failed_attempts = 0;
            $user->save();
            $this->events->log('locked', 'locked', $portal, $user, null, $method);
            Mail::to($user->email)->queue(new AccountLockedMail($user->name));

            return;
        }
        $user->save();
        $this->events->log('failed', 'invalid_'.$method, $portal, $user, null, $method);
    }

    public function registerSuccess(User $user): void
    {
        $user->forceFill(['failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => now()])->save();
    }
}
