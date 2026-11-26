<?php

namespace App\Providers;

use App\Services\Admin\PlatformSettingsService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimits();
        $this->configurePasswordResetLinks();
    }

    /**
     * There is no web "password.reset" route, so the default reset email
     * failed to build its link. Send admins to the admin console's page and
     * everyone else to the farm app.
     */
    private function configurePasswordResetLinks(): void
    {
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $base = $user->is_platform_admin
                ? rtrim((string) config('admin.frontend_url'), '/')
                : app(PlatformSettingsService::class)->getFarmAppUrl();

            return $base . '/reset-password?' . http_build_query(['token' => $token, 'email' => $user->email]);
        });
    }

    /**
     * Sign-in limits. Keyed on email + IP so one attacker cannot lock out an
     * account from everywhere, and on IP alone to slow spraying many emails.
     */
    private function configureRateLimits(): void
    {
        $perMinute = (int) config('admin.login_attempts_per_minute', 5);
        $key = fn (Request $request) => strtolower((string) $request->input('email')) . '|' . $request->ip();

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute($perMinute)->by('login:' . $key($request)),
            Limit::perMinute($perMinute * 6)->by('login-ip:' . $request->ip()),
        ]);

        RateLimiter::for('admin-login', fn (Request $request) => [
            Limit::perMinute($perMinute)->by('admin-login:' . $key($request)),
            Limit::perMinute($perMinute * 4)->by('admin-login-ip:' . $request->ip()),
        ]);

        RateLimiter::for('admin-otp', fn (Request $request) => Limit::perMinute(10)->by(
            'admin-otp:' . $request->input('challenge_id') . '|' . $request->ip()
        ));
    }
}
