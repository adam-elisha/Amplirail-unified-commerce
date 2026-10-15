<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Modules\Identity\Application\EmailOtp;
use Modules\Identity\Infrastructure\User;

/** Emails an 8-digit step-up code to admins who have no authenticator app enrolled (used for approvals). */
final class AdminStepUpController extends AdminController
{
    public function send(EmailOtp $otp): RedirectResponse
    {
        /** @var User $user */
        $user = User::query()->findOrFail($this->userId());
        if ($user->totp_confirmed_at !== null) {
            return back()->with('status', 'Use the code from your authenticator app.');
        }
        $result = $otp->issue($user, 'step_up');

        return back()->with($result === EmailOtp::SENT ? 'status' : 'error', $result === EmailOtp::SENT
            ? 'We emailed you an 8-digit code. It expires in '.config('amplirail.auth.otp_ttl_minutes').' minutes.'
            : 'Please wait before requesting another code.');
    }
}
