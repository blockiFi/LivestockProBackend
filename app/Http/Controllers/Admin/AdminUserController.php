<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\User;
use App\Services\Admin\AdminUserService;
use App\Traits\LogsAdminAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;

class AdminUserController extends ApiController
{
    use LogsAdminAction;

    public function __construct(private readonly AdminUserService $userService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->sendResponse($this->userService->list($request), 'Users retrieved');
    }

    public function show(User $user): JsonResponse
    {
        return $this->sendResponse($this->userService->show($user), 'User retrieved');
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email_verified' => 'sometimes|boolean',
            'is_platform_admin' => 'sometimes|boolean',
            'platform_admin_role' => ['sometimes', 'nullable', Rule::in(['super_admin', 'support', 'analyst', 'readonly'])],
        ]);

        $old = $user->only(array_keys($validated));
        $updated = $this->userService->update($user, $validated);

        $this->logAdminAction($request, 'user.update', 'user', $user->id, $old, $validated);

        return $this->sendResponse($updated, 'User updated');
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $status = $this->userService->sendPasswordReset($user);

        $this->logAdminAction($request, 'user.reset_password', 'user', $user->id);

        if ($status === Password::RESET_LINK_SENT) {
            return $this->sendResponse(null, 'Password reset link sent');
        }

        return $this->sendError('Failed to send password reset link', [], 500);
    }

    public function revokeTokens(Request $request, User $user): JsonResponse
    {
        $count = $this->userService->revokeAllTokens($user);

        $this->logAdminAction($request, 'user.revoke_tokens', 'user', $user->id, null, ['tokens_revoked' => $count]);

        return $this->sendResponse(['tokens_revoked' => $count], 'Tokens revoked');
    }

    public function activity(User $user): JsonResponse
    {
        return $this->sendResponse($this->userService->activity($user), 'User activity retrieved');
    }
}
