<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\Role;
use App\Models\User;
use App\Services\Admin\AdminAccessService;
use App\Traits\LogsAdminAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAccessController extends ApiController
{
    use LogsAdminAction;

    public function __construct(private readonly AdminAccessService $access)
    {
    }

    public function platformRoles(): JsonResponse
    {
        return $this->sendResponse([
            'roles' => [
                ['key' => 'readonly', 'name' => 'Read only', 'description' => 'Sees dashboards, lists and records. Cannot change anything.'],
                ['key' => 'analyst', 'name' => 'Analyst', 'description' => 'Everything read only can do, for reporting and analytics work.'],
                ['key' => 'support', 'name' => 'Support', 'description' => 'Helps customers: suspends farms, manages subscriptions, resets passwords, impersonates users.'],
                ['key' => 'super_admin', 'name' => 'Super admin', 'description' => 'Full control, including admin team, farm deletion, system tools and security settings.'],
            ],
            'matrix' => $this->access->platformMatrix(),
        ], 'Platform roles retrieved');
    }

    public function farmRolesOverview(): JsonResponse
    {
        return $this->sendResponse($this->access->farmRolesOverview(), 'Farm roles retrieved');
    }

    public function farmRoles(Farm $farm): JsonResponse
    {
        return $this->sendResponse($this->access->farmRoles($farm), 'Farm roles retrieved');
    }

    public function updateRolePermissions(Request $request, Farm $farm, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'permissions' => 'present|array',
            'permissions.*' => 'string|max:255',
        ]);

        $old = $role->permissions()->pluck('name')->sort()->values()->all();
        $updated = $this->access->syncRolePermissions($farm, $role, $validated['permissions']);
        $new = $updated->permissions->pluck('name')->sort()->values()->all();

        $this->logAdminAction($request, 'farm.role.permissions', 'farm', $farm->id, [
            'role' => $role->name,
            'removed' => array_values(array_diff($old, $new)),
        ], [
            'role' => $role->name,
            'added' => array_values(array_diff($new, $old)),
        ]);

        return $this->sendResponse($this->access->farmRoles($farm), 'Role permissions updated');
    }

    public function setMemberRole(Request $request, Farm $farm, User $user): JsonResponse
    {
        $validated = $request->validate(['role_id' => 'required|integer|exists:roles,id']);
        $role = Role::findOrFail($validated['role_id']);

        $change = $this->access->setMemberRole($farm, $user, $role);

        $this->logAdminAction($request, 'farm.member.role', 'farm', $farm->id,
            ['user_id' => $user->id, 'roles' => $change['old']],
            ['user_id' => $user->id, 'roles' => $change['new']],
        );

        return $this->sendResponse($change, 'Member role updated');
    }
}
