<?php

namespace App\Services\Admin;

use App\Mail\AdminLoginCodeMail;
use App\Models\AdminAuditLog;
use App\Models\AdminLoginChallenge;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Sign-in for the platform admin console: password, then (by default) a
 * six-digit code sent by email, then a short-lived admin-only token.
 */
class AdminAuthService
{
    public function __construct(private readonly PlatformSettingsService $settings)
    {
    }

    /** Returns the user when the password is right and the account may use the admin console. */
    public function checkCredentials(string $email, string $password): ?User
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            return null;
        }

        if (! $user->is_platform_admin || $user->deactivated_at !== null) {
            return null;
        }

        return $user;
    }

    public function otpRequired(): bool
    {
        return $this->settings->getAdminSecurity()['otp_required'];
    }

    public function startChallenge(User $user, ?string $ip): AdminLoginChallenge
    {
        // One live challenge per admin: older unused codes stop working.
        AdminLoginChallenge::where('user_id', $user->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);

        $code = (string) random_int(100000, 999999);
        $minutes = (int) config('admin.otp_ttl_minutes', 10);

        $challenge = AdminLoginChallenge::create([
            'user_id' => $user->id,
            'public_id' => (string) Str::uuid(),
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes($minutes),
            'ip_address' => $ip,
        ]);

        Mail::to($user->email)->send(new AdminLoginCodeMail($user->name, $code, $minutes, $ip));

        return $challenge;
    }

    /**
     * @return array{0: ?User, 1: ?string} the user, or null and the reason
     */
    public function verifyChallenge(string $publicId, string $code): array
    {
        $challenge = AdminLoginChallenge::where('public_id', $publicId)->first();

        if (! $challenge || ! $challenge->isUsable()) {
            return [null, 'This code has expired. Sign in again to get a new one.'];
        }

        if (! Hash::check($code, $challenge->code_hash)) {
            $challenge->increment('attempts');
            $left = max(0, (int) config('admin.otp_max_attempts', 5) - $challenge->attempts);

            return [null, $left > 0
                ? "That code is not right. {$left} " . ($left === 1 ? 'try' : 'tries') . ' left.'
                : 'Too many wrong codes. Sign in again to get a new one.'];
        }

        $challenge->update(['consumed_at' => now()]);
        $user = $challenge->user;

        if (! $user || ! $user->is_platform_admin || $user->deactivated_at !== null) {
            return [null, 'This account cannot use the admin console.'];
        }

        return [$user, null];
    }

    /**
     * @return array{token: string, expires_at: string, user: User}
     */
    public function issueToken(User $user, ?string $ip, ?string $userAgent): array
    {
        $hours = $this->settings->getAdminSecurity()['session_hours'];
        $expiresAt = now()->addHours($hours);

        $token = $user->createToken('admin-session', [config('admin.token_ability')], $expiresAt);
        $user->forceFill(['last_admin_login_at' => now(), 'last_login_at' => now()])->save();

        AdminAuditLog::create([
            'admin_user_id' => $user->id,
            'action' => 'admin.login',
            'resource_type' => 'user',
            'resource_id' => $user->id,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'created_at' => now(),
        ]);

        return [
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => $user->fresh(),
        ];
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $visible . str_repeat('•', max(1, mb_strlen($local) - mb_strlen($visible))) . '@' . $domain;
    }
}
