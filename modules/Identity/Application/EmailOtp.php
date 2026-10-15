<?php

declare(strict_types=1);

namespace Modules\Identity\Application;

use App\Support\Ids;
use Illuminate\Support\Facades\Mail;
use Modules\Identity\Infrastructure\OtpChallenge;
use Modules\Identity\Infrastructure\User;
use Modules\Identity\Mail\OneTimeCodeMail;

/**
 * Emailed 8-digit code for the merchant portal (master document 5.1.1): hash stored, 10 minutes, single use,
 * resend after 45 seconds, at most 5 codes per hour. Wrong codes count toward the account lockout.
 */
final class EmailOtp
{
    public const SENT = 'sent';

    public const TOO_SOON = 'too_soon';

    public const HOURLY_LIMIT = 'hourly_limit';

    public function __construct(
        private readonly PasswordSignIn $passwords,
        private readonly AuthEventLogger $events,
    ) {}

    public function issue(User $user, string $purpose = 'sign_in'): string
    {
        $last = OtpChallenge::query()->where('user_id', $user->id)->orderByDesc('created_at')->first();
        if ($last !== null && $last->created_at->gt(now()->subSeconds((int) config('amplirail.auth.otp_resend_seconds')))) {
            return self::TOO_SOON;
        }
        $lastHour = OtpChallenge::query()->where('user_id', $user->id)->where('created_at', '>', now()->subHour())->count();
        if ($lastHour >= (int) config('amplirail.auth.otp_max_per_hour')) {
            return self::HOURLY_LIMIT;
        }

        $digits = (int) config('amplirail.auth.otp_digits');
        $code = str_pad((string) random_int(0, (10 ** $digits) - 1), $digits, '0', STR_PAD_LEFT);

        // Only one live code per user and purpose.
        OtpChallenge::query()->where('user_id', $user->id)->where('purpose', $purpose)->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);
        OtpChallenge::query()->create([
            'id' => Ids::new('otp'),
            'user_id' => $user->id,
            'purpose' => $purpose,
            'code_hash' => $this->hash($user->id, $code),
            'expires_at' => now()->addMinutes((int) config('amplirail.auth.otp_ttl_minutes')),
            'attempts' => 0,
            'created_at' => now(),
        ]);
        Mail::to($user->email)->send(new OneTimeCodeMail($code, $purpose));
        $this->events->log('mfa_challenged', 'sent', $user->portal, $user, null, 'email_code');

        return self::SENT;
    }

    public function verify(User $user, string $code, string $purpose = 'sign_in'): bool
    {
        $challenge = OtpChallenge::query()->where('user_id', $user->id)->where('purpose', $purpose)
            ->whereNull('consumed_at')->orderByDesc('created_at')->first();

        $valid = $challenge !== null
            && $challenge->expires_at->isFuture()
            && preg_match('/^\d{'.(int) config('amplirail.auth.otp_digits').'}$/', $code) === 1
            && hash_equals($challenge->code_hash, $this->hash($user->id, $code));

        if ($valid) {
            $challenge->forceFill(['consumed_at' => now()])->save();
            $this->events->log('mfa_verified', 'ok', $user->portal, $user, null, 'email_code');

            return true;
        }
        if ($challenge !== null) {
            $challenge->increment('attempts');
        }
        $this->passwords->registerFailure($user, $user->portal, 'email_code');

        return false;
    }

    private function hash(string $userId, string $code): string
    {
        return hash_hmac('sha256', $userId.'|'.$code, (string) config('app.key'));
    }
}
