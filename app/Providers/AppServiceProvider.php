<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->environment('production'));

        // Passwords: 12+ characters, mixed case, numbers, symbols, not in known breaches (production).
        Password::defaults(fn () => $this->app->environment('production')
            ? Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised()
            : Password::min(12)->mixedCase()->numbers()->symbols());

        // Rate limits (SECURITY.md: login, reset, tokenize and authorize).
        RateLimiter::for('sign-in', fn (Request $r) => [
            Limit::perMinute(10)->by('ip:'.$r->ip()),
            Limit::perMinute(5)->by('email:'.mb_strtolower((string) $r->input('email'))),
        ]);
        RateLimiter::for('otp', fn (Request $r) => Limit::perMinute(10)->by('otp:'.$r->session()->getId()));
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(6000)->by('key:'.substr(hash('sha256', (string) $r->bearerToken()), 0, 16)));
        RateLimiter::for('oauth', fn (Request $r) => Limit::perMinute(30)->by('ip:'.$r->ip()));
        RateLimiter::for('pay', fn (Request $r) => [Limit::perMinute(10)->by('ip:'.$r->ip()), Limit::perHour(60)->by('ip:'.$r->ip())]);
    }
}
