<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\User;
use App\Services\Admin\AdminTeamService;
use App\Services\Admin\AdminUserService;
use App\Support\CsvExport;
use App\Traits\LogsAdminAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminUserController extends ApiController
{
    use LogsAdminAction;

    public function __construct(
        private readonly AdminUserService $userService,
        private readonly AdminTeamService $team,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->sendResponse($this->userService->list($request), 'Users retrieved');
    }

    public function export(Request $request): StreamedResponse
    {
        $this->logAdminAction($request, 'user.export', 'user', null, null, $request->only(['search', 'type', 'verified', 'status', 'farm_id']));

        return CsvExport::stream($this->userService->query($request), [
            'ID' => fn (User $u) => $u->id,
            'Name' => fn (User $u) => $u->name,
            'Email' => fn (User $u) => $u->email,
            'Phone' => fn (User $u) => $u->phone,
            'Farms' => fn (User $u) => $u->farms_count,
            'Verified' => fn (User $u) => $u->email_verified_at !== null,
            'Admin role' => fn (User $u) => $u->is_platform_admin ? $u->platform_admin_role : '',
            'Deactivated' => fn (User $u) => $u->deactivated_at,
            'Last sign-in' => fn (User $u) => $u->last_login_at,
            'Joined' => fn (User $u) => $u->created_at,
        ], 'users-' . now()->format('Y-m-d') . '.csv');
    }

    public function show(User $user): JsonResponse
    {
        return $this->sendResponse($this->userService->show($user), 'User retrieved');
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|nullable|string|max:20',
            'email_verified' => 'sometimes|boolean',
            // Kept for older clients; routed through the admin team rules.
            'is_platform_admin' => 'sometimes|boolean',
            'platform_admin_role' => ['sometimes', 'nullable', Rule::in(AdminTeamService::ROLES)],
        ]);

        $touchesAccess = array_key_exists('is_platform_admin', $validated) || array_key_exists('platform_admin_role', $validated);

        if ($touchesAccess) {
            // Changing who is an admin is a super admin action, whatever route is used.
            if ($request->user()->platform_admin_role !== 'super_admin') {
                return $this->sendError('Only super admins can change admin access', [], 403);
            }

            $old = $user->only(['is_platform_admin', 'platform_admin_role']);
            $makeAdmin = $validated['is_platform_admin'] ?? $user->is_platform_admin;
            $role = $validated['platform_admin_role'] ?? $user->platform_admin_role ?? 'readonly';

            if (! $makeAdmin) {
                $this->team->remove($request->user(), $user);
            } elseif (! $user->is_platform_admin) {
                $this->team->invite($request->user(), $user->email, null, $role);
            } else {
                $this->team->changeRole($request->user(), $user, $role);
            }

            $this->logAdminAction($request, 'user.admin_access', 'user', $user->id, $old, $user->fresh()->only(['is_platform_admin', 'platform_admin_role']));
        }

        $profile = array_diff_key($validated, array_flip(['is_platform_admin', 'platform_admin_role']));
        if ($profile !== []) {
            $old = $user->only(array_keys($profile));
            $this->userService->update($user, $profile);
            $this->logAdminAction($request, 'user.update', 'user', $user->id, $old, $profile);
        }

        return $this->sendResponse($user->fresh(), 'User updated');
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate(['reason' => 'nullable|string|max:500']);

        if ($user->id === $request->user()->id) {
            return $this->sendError('You cannot deactivate your own account', [], 422);
        }
        if ($user->is_platform_admin && $request->user()->platform_admin_role !== 'super_admin') {
            return $this->sendError('Only super admins can deactivate admin accounts', [], 403);
        }

        $updated = $this->userService->deactivate($user, $validated['reason'] ?? null);
        $this->logAdminAction($request, 'user.deactivate', 'user', $user->id, null, ['reason' => $validated['reason'] ?? null]);

        return $this->sendResponse($updated, 'User deactivated and signed out everywhere');
    }

    public function reactivate(Request $request, User $user): JsonResponse
    {
        $updated = $this->userService->reactivate($user);
        $this->logAdminAction($request, 'user.reactivate', 'user', $user->id);

        return $this->sendResponse($updated, 'User reactivated');
    }

    public function bulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['verify', 'revoke_tokens', 'deactivate', 'reactivate', 'reset_password'])],
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'integer|exists:users,id',
            'reason' => 'nullable|string|max:500',
        ]);

        $actor = $request->user();
        $done = [];
        $skipped = [];

        foreach (User::whereIn('id', $validated['ids'])->get() as $user) {
            $protected = $user->id === $actor->id
                || ($user->is_platform_admin && $actor->platform_admin_role !== 'super_admin');

            if ($protected && in_array($validated['action'], ['deactivate', 'revoke_tokens'], true)) {
                $skipped[] = ['id' => $user->id, 'reason' => $user->id === $actor->id ? 'Your own account' : 'Admin account'];
                continue;
            }

            match ($validated['action']) {
                'verify' => $this->userService->update($user, ['email_verified' => true]),
                'revoke_tokens' => $this->userService->revokeAllTokens($user),
                'deactivate' => $user->deactivated_at ? null : $this->userService->deactivate($user, $validated['reason'] ?? null),
                'reactivate' => $this->userService->reactivate($user),
                'reset_password' => Password::sendResetLink(['email' => $user->email]),
            };
            $done[] = $user->id;
        }

        $this->logAdminAction($request, "user.bulk.{$validated['action']}", 'user', null, null, [
            'ids' => $done,
            'skipped' => $skipped,
        ]);

        return $this->sendResponse(['updated' => count($done), 'ids' => $done, 'skipped' => $skipped], 'Bulk action finished');
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
        if ($user->is_platform_admin && $user->id !== $request->user()->id && $request->user()->platform_admin_role !== 'super_admin') {
            return $this->sendError('Only super admins can sign out other admins', [], 403);
        }

        $count = $this->userService->revokeAllTokens($user);

        $this->logAdminAction($request, 'user.revoke_tokens', 'user', $user->id, null, ['tokens_revoked' => $count]);

        return $this->sendResponse(['tokens_revoked' => $count], 'Tokens revoked');
    }

    public function activity(User $user): JsonResponse
    {
        return $this->sendResponse($this->userService->activity($user), 'User activity retrieved');
    }
}
