<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\AdminLoginChallenge;
use App\Services\Admin\AdminAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAuthController extends ApiController
{
    public function __construct(private readonly AdminAuthService $auth)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = $this->auth->checkCredentials($validated['email'], $validated['password']);

        // Same message for wrong password, unknown email and non-admins.
        if (! $user) {
            return $this->sendError('The email or password is incorrect, or this account has no admin access.', [], 401);
        }

        if ($this->auth->otpRequired()) {
            $challenge = $this->auth->startChallenge($user, $request->ip());

            return $this->sendResponse([
                'otp_required' => true,
                'challenge_id' => $challenge->public_id,
                'email_hint' => AdminAuthService::maskEmail($user->email),
                'expires_at' => $challenge->expires_at->toIso8601String(),
            ], 'We sent a sign-in code to your email');
        }

        return $this->sendResponse(
            ['otp_required' => false] + $this->auth->issueToken($user, $request->ip(), $request->userAgent()),
            'Signed in'
        );
    }

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => 'required|uuid',
            'code' => 'required|string|size:6',
        ]);

        [$user, $error] = $this->auth->verifyChallenge($validated['challenge_id'], $validated['code']);

        if (! $user) {
            return $this->sendError($error, [], 422);
        }

        return $this->sendResponse(
            $this->auth->issueToken($user, $request->ip(), $request->userAgent()),
            'Signed in'
        );
    }

    public function resend(Request $request): JsonResponse
    {
        $validated = $request->validate(['challenge_id' => 'required|uuid']);

        $challenge = AdminLoginChallenge::where('public_id', $validated['challenge_id'])->first();

        if (! $challenge || $challenge->consumed_at !== null || ! $challenge->user) {
            return $this->sendError('Sign in again to get a new code.', [], 422);
        }

        $fresh = $this->auth->startChallenge($challenge->user, $request->ip());

        return $this->sendResponse([
            'otp_required' => true,
            'challenge_id' => $fresh->public_id,
            'email_hint' => AdminAuthService::maskEmail($challenge->user->email),
            'expires_at' => $fresh->expires_at->toIso8601String(),
        ], 'We sent a new code');
    }

    public function me(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        return $this->sendResponse([
            'user' => $request->user(),
            'expires_at' => $token?->expires_at?->toIso8601String(),
        ], 'Admin retrieved');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->sendResponse(null, 'Signed out');
    }
}
