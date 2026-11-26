<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\User;
use App\Services\Admin\AdminTeamService;
use App\Traits\LogsAdminAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminTeamController extends ApiController
{
    use LogsAdminAction;

    public function __construct(private readonly AdminTeamService $team)
    {
    }

    public function index(): JsonResponse
    {
        return $this->sendResponse($this->team->list(), 'Admin team retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'name' => 'nullable|string|max:255',
            'role' => ['required', Rule::in(AdminTeamService::ROLES)],
        ]);

        $result = $this->team->invite($request->user(), $validated['email'], $validated['name'] ?? null, $validated['role']);

        $this->logAdminAction($request, 'admin_team.add', 'user', $result['user']->id, null, [
            'email' => $result['user']->email,
            'role' => $validated['role'],
            'account_created' => $result['created'],
        ]);

        return $this->sendResponse($result, $result['created']
            ? 'Account created and a set-password email sent'
            : 'Admin access granted');
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate(['role' => ['required', Rule::in(AdminTeamService::ROLES)]]);
        $old = $user->platform_admin_role;

        $updated = $this->team->changeRole($request->user(), $user, $validated['role']);

        $this->logAdminAction($request, 'admin_team.role_change', 'user', $user->id, ['role' => $old], ['role' => $validated['role']]);

        return $this->sendResponse($updated, 'Admin role updated');
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $old = $user->platform_admin_role;
        $updated = $this->team->remove($request->user(), $user);

        $this->logAdminAction($request, 'admin_team.remove', 'user', $user->id, ['role' => $old], ['role' => null]);

        return $this->sendResponse($updated, 'Admin access removed');
    }
}
