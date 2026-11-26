<?php

return [
    /*
    | Admin sign-in. Platform admins sign in through /api/admin/auth/login,
    | which issues a short-lived token carrying the "platform-admin" ability.
    | Farm app tokens are not accepted on /api/admin routes.
    */
    'token_ability' => 'platform-admin',

    // Where the admin console runs (used in password reset links for admins).
    'frontend_url' => env('ADMIN_FRONTEND_URL', 'http://localhost:5174'),

    // Default session length in hours; a super admin can change it in Settings.
    'session_hours' => (int) env('ADMIN_SESSION_HOURS', 8),

    // Email one-time code after the password. A super admin can turn it off in Settings.
    'otp_required' => (bool) env('ADMIN_OTP_REQUIRED', true),
    'otp_ttl_minutes' => 10,
    'otp_max_attempts' => 5,

    // Sign-in attempts allowed per minute per email + IP.
    'login_attempts_per_minute' => 5,
];
