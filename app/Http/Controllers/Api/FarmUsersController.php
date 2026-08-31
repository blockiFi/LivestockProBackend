<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\User;
use App\Models\FarmUserInvitation;
use App\Services\FarmEntitlementService;
use App\Traits\HasFarmPermissions;
use App\Traits\RegisterEvents;
use App\Traits\ManagesFarmRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class FarmUsersController extends ApiController
{
    use HasFarmPermissions, RegisterEvents, ManagesFarmRoles;

    /**
     * List farm users with their roles and permissions.
     */
    public function index(Request $request, Farm $farm)
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('view users', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view farm users', [], 403);
        }

        $users = $farm->users()
            ->with(['roles' => function ($query) use ($farm) {
                $query->where('roles.farm_id', $farm->id)
                    ->with('permissions');
            }])
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->roles->map(function ($role) {
                        return [
                            'id' => $role->id,
                            'name' => $role->name,
                            'permissions' => $role->permissions->pluck('name'),
                        ];
                    }),
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                ];
            });

        return $this->sendResponse($users, 'Farm users retrieved successfully');
    }

    /**
     * Attach an existing user to the farm (or later accept invite).
     */
    public function store(Request $request, Farm $farm)
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('manage users', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage users', [], 403);
        }

        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'role' => 'required|string|in:owner,manager,worker',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation Error', $validator->errors()->all());
        }

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return $this->sendError('User not found for the given email', [], 404);
        }
        $roleName = $request->role;

        if ($farm->users()->where('user_id', $user->id)->exists()) {
            return $this->sendError('User already belongs to this farm', [], 409);
        }

        if ($response = $this->ensureEntitled($farm, FarmEntitlementService::ACTION_ADD_USER)) {
            return $response;
        }

        // Attach user to the farm
        $farm->users()->attach($user->id);

        $this->RegisterEvent(
            farmId: $farm->id,
            eventType: 'user_added',
            tableName: 'user',
            tableId: $user->id
        );

        // Ensure roles and permissions exist and assign role
        $rolesAndPermissions = $this->getRolesAndPermissions();
        if (!array_key_exists($roleName, $rolesAndPermissions)) {
            return $this->sendError('Invalid role definition in permissions map', [], 422);
        }

        $role = Role::where('name', $roleName)
            ->where('farm_id', $farm->id)
            ->first();

        if (!$role) {
            $permissions = $rolesAndPermissions[$roleName];
            $role = $this->addFarmRole($farm, $roleName, $permissions);
            if (!$role) {
                return $this->sendError('Failed to create role', [], 500);
            }
        }

        if ($role->name !== $roleName) {
            return $this->sendError('Role mismatch during assignment', [], 500);
        }

        $user->assignRole($roleName);

        return $this->sendResponse(null, 'User added to farm successfully');
    }

    /**
     * Update a farm user membership (role and/or status).
     */
    public function update(Request $request, Farm $farm, User $user)
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('manage users', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage users', [], 403);
        }

        $validator = Validator::make($request->all(), [
            'role' => 'sometimes|required|string|in:owner,manager,worker',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation Error', $validator->errors()->all());
        }

        if ($request->has('role')) {
            $roleName = $request->role;

            // Remove all farm-specific roles first
            $farmRoles = Role::where('farm_id', $farm->id)->get();
            foreach ($farmRoles as $role) {
                app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
                $user->removeRole($role);
            }

            // Reassign the requested role
            $rolesAndPermissions = $this->getRolesAndPermissions();
            if (!array_key_exists($roleName, $rolesAndPermissions)) {
                return $this->sendError('Invalid role definition in permissions map', [], 422);
            }

            $role = Role::where('name', $roleName)
                ->where('farm_id', $farm->id)
                ->first();

            if (!$role) {
                $permissions = $rolesAndPermissions[$roleName];
                $role = $this->addFarmRole($farm, $roleName, $permissions);
                if (!$role) {
                    return $this->sendError('Failed to create role', [], 500);
                }
            }

            $user->assignRole($roleName);
        }

        return $this->sendResponse(null, 'Farm user updated successfully');
    }

    /**
     * Detach a user from the farm and remove farm-specific roles.
     */
    public function destroy(Request $request, Farm $farm, User $user)
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('manage users', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage users', [], 403);
        }

        // Remove all roles related to this farm
        $farmRoles = Role::where('farm_id', $farm->id)->get();
        foreach ($farmRoles as $role) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
            $user->removeRole($role);
        }

        // Detach from pivot
        $farm->users()->detach($user->id);

        $this->RegisterEvent(
            farmId: $farm->id,
            eventType: 'user_removed',
            tableName: 'user',
            tableId: $user->id
        );

        return $this->sendResponse(null, 'User removed from farm successfully');
    }

    /**
     * Invite a (possibly new) user by email to join the farm with a role.
     */
    public function invite(Request $request, Farm $farm)
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('manage users', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage users', [], 403);
        }

        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'role' => 'required|string|in:owner,manager,worker',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation Error', $validator->errors()->all());
        }

        if ($response = $this->ensureEntitled($farm, FarmEntitlementService::ACTION_ADD_USER)) {
            return $response;
        }

        $token = Str::uuid()->toString();

        $invitation = FarmUserInvitation::create([
            'farm_id' => $farm->id,
            'email' => $request->email,
            'role' => $request->role,
            'token' => $token,
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        // TODO: dispatch notification / email here if desired.

        return $this->sendResponse($invitation, 'Invitation created successfully');
    }

    /**
     * Resend an existing pending invitation (refresh expiry and re-send).
     */
    public function resendInvite(Request $request, Farm $farm, FarmUserInvitation $invitation)
    {
        if ($invitation->farm_id !== $farm->id) {
            return $this->sendError('Invitation does not belong to this farm', [], 404);
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('manage users', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage users', [], 403);
        }

        if ($invitation->status !== 'pending') {
            return $this->sendError('Only pending invitations can be resent', [], 422);
        }

        $invitation->expires_at = now()->addDays(7);
        $invitation->save();

        // TODO: dispatch notification / email here if desired.

        return $this->sendResponse($invitation, 'Invitation resent successfully');
    }
}

