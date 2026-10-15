<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Modules\Identity\Application\AuthenticatorCodes;
use Modules\Identity\Application\AuthEventLogger;
use Modules\Identity\Application\EmailOtp;
use Modules\Identity\Application\PasswordSignIn;
use Modules\Identity\Application\UserAdministration;
use Modules\Identity\Domain\Totp;
use Modules\Identity\Infrastructure\User;
use Modules\Identity\Mail\NewDeviceMail;

/**
 * Master document 5.1.1. Step 1: email + password. Step 2: authenticator app (admin console, enrolled on first sign-in)
 * or an emailed 8-digit code (merchant portal). The session is only authenticated after step 2.
 */
final class SignInController
{
    /**
     * Admin second step. 'off' = password only (TEMPORARY, development only), 'email' = emailed 8-digit code,
     * 'totp' = authenticator app. Changed to 'off' on 2026-10-14 at Adam's request while the platform is in development.
     */
    private const ADMIN_SECOND_STEP = 'off';

    public function __construct(
        private readonly PasswordSignIn $passwords,
        private readonly EmailOtp $emailOtp,
        private readonly AuthenticatorCodes $totp,
        private readonly AuthEventLogger $events,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $portal = $this->portal($request);
        if (Auth::check() && $request->session()->get('mfa_passed') === Auth::id()) {
            return redirect()->route($portal === 'admin' ? 'admin.overview' : 'merchant.dashboard');
        }

        return view('auth.sign-in', ['portal' => $portal]);
    }

    public function login(Request $request): RedirectResponse
    {
        $portal = $this->portal($request);
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string', 'max:200']]);
        [$result, $user] = $this->passwords->attempt($portal, (string) $data['email'], (string) $data['password']);
        if ($result === PasswordSignIn::LOCKED) {
            return back()->withInput(['email' => $data['email']])->with('error', 'This account is locked for '.config('amplirail.auth.lockout_minutes').' minutes after too many attempts.');
        }
        if ($result !== PasswordSignIn::OK || $user === null) {
            return back()->withInput(['email' => $data['email']])->with('error', 'Email or password is incorrect.');
        }

        $brand = app(\App\Support\Branding\BrandResolver::class)->current();
        if ($brand->tenantId !== null && $user->primaryMembership()?->tenant_id !== $brand->tenantId) {
            return back()->withInput(['email' => $data['email']])->with('error', 'Email or password is incorrect.');
        }

        $request->session()->regenerate();
        $request->session()->put(['auth.pending' => $user->id, 'auth.portal' => $portal]);

        if ($portal === 'admin') {
            if (self::ADMIN_SECOND_STEP === 'off') {
                return $this->complete($request, $user, 'password');
            }
            if (self::ADMIN_SECOND_STEP === 'email') {
                $this->emailOtp->issue($user);

                return redirect()->route('admin.verify');
            }

            return redirect()->route($user->totp_confirmed_at === null ? 'admin.totp' : 'admin.verify');
        }
        $this->emailOtp->issue($user);

        return redirect()->route('merchant.verify');
    }

    public function verifyForm(Request $request): View|RedirectResponse
    {
        $portal = $this->portal($request);
        if ($this->pending($request, $portal) === null) {
            return redirect()->route($portal.'.login');
        }

        return view('auth.verify', ['portal' => $portal]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $portal = $this->portal($request);
        $user = $this->pending($request, $portal);
        if ($user === null) {
            return redirect()->route($portal.'.login');
        }
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:12']])['code'];
        $ok = ($portal === 'admin' && self::ADMIN_SECOND_STEP === 'totp') ? $this->totp->verify($user, $code) : $this->emailOtp->verify($user, $code);
        if (! $ok) {
            $user->refresh();

            return $user->isLocked()
                ? redirect()->route($portal.'.login')->with('error', 'This account is locked after too many attempts.')
                : back()->with('error', 'That code is not valid. Try again.');
        }

        return $this->complete($request, $user, ($portal === 'admin' && self::ADMIN_SECOND_STEP === 'totp') ? 'authenticator' : 'email_code');
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $this->pending($request, 'merchant');
        if ($user === null) {
            return redirect()->route('merchant.login');
        }
        $result = $this->emailOtp->issue($user);

        return back()->with($result === EmailOtp::SENT ? 'status' : 'error', match ($result) {
            EmailOtp::SENT => 'A new code was sent to your email.',
            EmailOtp::TOO_SOON => 'Please wait 45 seconds before asking for another code.',
            default => 'Too many codes this hour. Try again later.',
        });
    }

    public function totpForm(Request $request): View|RedirectResponse
    {
        $user = $this->pending($request, 'admin');
        if ($user === null || $user->totp_confirmed_at !== null || self::ADMIN_SECOND_STEP !== 'totp') {
            return redirect()->route('admin.login');
        }
        $secret = (string) ($request->session()->get('totp_setup_secret') ?: Totp::generateSecret());
        $request->session()->put('totp_setup_secret', $secret);
        $uri = Totp::uri((string) config('amplirail.auth.totp_issuer'), $user->email, $secret);
        $svg = (string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', (new Writer(new ImageRenderer(new RendererStyle(180, 1), new SvgImageBackEnd)))->writeString($uri));

        return view('auth.totp-setup', ['secret' => $secret, 'qr' => $svg]);
    }

    public function totpConfirm(Request $request): RedirectResponse
    {
        $user = $this->pending($request, 'admin');
        $secret = (string) $request->session()->get('totp_setup_secret');
        if ($user === null || $secret === '') {
            return redirect()->route('admin.login');
        }
        $code = (string) $request->validate(['code' => ['required', 'digits:6']])['code'];
        if (! $this->totp->confirmEnrollment($user, $secret, $code)) {
            return back()->with('error', 'That code did not match. Check the time on your phone and try again.');
        }
        $request->session()->forget('totp_setup_secret');

        return $this->complete($request, $user->refresh(), 'authenticator');
    }

    public function logout(Request $request): RedirectResponse
    {
        $portal = $this->portal($request);
        /** @var User|null $user */
        $user = Auth::user();
        if ($user !== null) {
            $this->events->log('sign_out', 'ok', $portal, $user);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route($portal.'.login')->with('status', 'You are signed out.');
    }

    public function invitationForm(Request $request, string $token, UserAdministration $users): View|RedirectResponse
    {
        if ($users->findInvitation($token) === null) {
            return redirect()->route($this->portal($request).'.login')->with('error', 'This invitation link is invalid or expired.');
        }

        return view('auth.invitation', ['portal' => $this->portal($request), 'token' => $token]);
    }

    public function acceptInvitation(Request $request, string $token, UserAdministration $users): RedirectResponse
    {
        $portal = $this->portal($request);
        $data = $request->validate(['password' => ['required', 'confirmed', Password::defaults()]]);
        $user = $users->acceptInvitation($token, (string) $data['password']);
        if ($user === null || $user->portal !== $portal) {
            return redirect()->route($portal.'.login')->with('error', 'This invitation link is invalid or expired.');
        }

        return redirect()->route($portal.'.login')->with('status', 'Your password is set. Sign in to continue.');
    }

    private function complete(Request $request, User $user, string $method): RedirectResponse
    {
        $portal = (string) $request->session()->pull('auth.portal');
        $request->session()->forget('auth.pending');
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(['mfa_passed' => $user->id, 'last_activity' => time(), 'environment' => 'sandbox']);
        $this->passwords->registerSuccess($user);
        $newDevice = $this->rememberDevice($request, $user);
        $this->events->log('sign_in', 'ok', $portal, $user, null, $method, $newDevice);

        return redirect()->intended(route($portal === 'admin' ? 'admin.overview' : 'merchant.dashboard'));
    }

    /** New device or browser: alert by email (not on the very first sign-in) and flag in the sign-in log. */
    private function rememberDevice(Request $request, User $user): bool
    {
        $hash = hash('sha256', $user->id.'|'.$request->userAgent());
        $known = DB::table('known_devices')->where('user_id', $user->id);
        $first = ! (clone $known)->exists();
        if ((clone $known)->where('device_hash', $hash)->exists()) {
            return false;
        }
        DB::table('known_devices')->insert(['user_id' => $user->id, 'device_hash' => $hash, 'first_seen_at' => now()]);
        if (! $first) {
            Mail::to($user->email)->queue(new NewDeviceMail($user->name, (string) $request->ip(), mb_substr((string) $request->userAgent(), 0, 120), now()->toDayDateTimeString()));
        }

        return ! $first;
    }

    private function pending(Request $request, string $portal): ?User
    {
        if ($request->session()->get('auth.portal') !== $portal) {
            return null;
        }
        $id = $request->session()->get('auth.pending');

        return is_string($id) ? User::query()->find($id) : null;
    }

    private function portal(Request $request): string
    {
        return $request->is('admin*') ? 'admin' : 'merchant';
    }
}
