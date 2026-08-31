<?php

namespace App\Services\Admin;

use App\Models\User;
use Carbon\Carbon;

class AdminImpersonationService
{
    public function createToken(User $admin, User $target): array
    {
        $tokenName = "impersonation:{$admin->id}:{$target->id}";

        $token = $target->createToken($tokenName, ['impersonate'], Carbon::now()->addMinutes(15));

        return [
            'token' => $token->plainTextToken,
            'expires_at' => Carbon::now()->addMinutes(15)->toIso8601String(),
            'user' => $target->only(['id', 'name', 'email']),
            'impersonated_by' => $admin->only(['id', 'name', 'email']),
        ];
    }

    public function endImpersonation(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token && str_starts_with($token->name, 'impersonation:')) {
            $token->delete();
        }
    }
}
