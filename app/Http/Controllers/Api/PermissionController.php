<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Models\User;
use App\Models\Farm;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Traits\RegisterEvents;
use App\Exceptions\PermissionDoesNotExist;
use App\Models\Group;
use Spatie\Permission\PermissionRegistrar;
use App\Services\PermissionGroupAssignmentService;
class PermissionController extends ApiController
{
    use RegisterEvents;

    /**
     * Get all permissions
     */
    public function index($farm)
    {   
        
         app(PermissionRegistrar::class)->setPermissionsTeamId($farm);
        if (!auth()->user()->can('view permissions')) {
            return $this->sendError('You do not have permission to view permissions', [], 403);
        }

        $permissions = Permission::all();
        return $this->sendResponse($permissions, 'Permissions retrieved successfully');
    }

    /**
     * Get all permission groups with their permissions
     */
    public function getGroupPermissions($farm){
        $farm = Farm::findOrFail($farm);
        
        // Set the team context (important for spatie/permission multi-tenancy)
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        
        if (!auth()->user()->can('view permissions', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view permissions', [], 403);
        }

        $assignmentService = app(PermissionGroupAssignmentService::class);
        $assignmentService->assignAll();

        $permissionGroups = Group::with(['permissions' => fn ($query) => $query->orderBy('name')])
            ->orderBy('name')
            ->get();

        return $this->sendResponse([
            'groups' => $permissionGroups,
            'total_permissions' => $assignmentService->totalPermissionCount(),
        ], 'Grouped permissions retrieved successfully');
    }
    public function getRoles($farm)
    {   
         $farm = Farm::findOrFail($farm);
         app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);    
        if (!auth()->user()->can('view user roles' , 'api' , $farm->id )) {
            return $this->sendError('You do not have permission to view roles', [], 403);
        }


        $roles = Role::where('farm_id', $farm->id)->with('permissions')->get();
        return $this->sendResponse($roles, 'Roles retrieved successfully');
    }

    /**
     * Create a new role
     */
    public function createRole(Request $request)
    {   
        $validator = Validator::make($request->all(), [
            'farm_id' => 'required|integer|exists:farms,id',
            'name' => 'required|string',
            'permissions' => 'required|array',
            'permissions.*' => 'integer', // just check they're integers here
        ]);
        
        if ($validator->fails()) {
            return $this->sendValidationError('Validation Error', $validator->errors()->all());
        }

        $farm = Farm::findOrFail($request->farm_id);
        
        // Check if role name already exists for this farm
        $existingRole = Role::where('name', $request->name)
            ->where('farm_id', $request->farm_id)
            ->first();
        if ($existingRole) {
            return $this->sendValidationError('Validation Error', ['A role with this name already exists in this farm']);
        }
        
        // Set the team context (i.e., farm) before any permission checks
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        
        if (!auth()->user()->can('manage roles', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage roles', [], 403);
        }
        // return Permission::all();
        // Manually validate permission IDs with correct guard
         $invalidPermissions = collect($request->permissions)
            ->filter(function ($id) {
                return !Permission::where('id', $id)->where('guard_name', 'api')->exists();
            });
        
        if ($invalidPermissions->isNotEmpty()) {
            return $this->sendValidationError('Validation Error', [
                'Invalid permission IDs for guard Api: ' . $invalidPermissions->implode(', ')
            ]);
            
        }
        try {
            DB::beginTransaction();

            // Verify all permissions exist
            foreach ($request->permissions as $permissionId) {
                $permission = Permission::find($permissionId);
                if (!$permission) {
                    throw new PermissionDoesNotExist("Permission with ID {$permissionId}");
                }
            }

          $role = Role::create([
              'name' => $request->name,
              'guard_name' => 'api',
              'farm_id' => $request->farm_id
              
          ]);
          $role->syncPermissions($request->permissions);

            $this->RegisterEvent(
                farmId: $request->farm_id,
                eventType: 'role_created',
                tableName: 'role',
                tableId: $role->id

                
            );

            DB::commit();

            return $this->sendResponse($role->load('permissions'), 'Role created successfully', 201);
        } catch (PermissionDoesNotExist $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Error creating role', [$e->getMessage()], 500);
        }
    }

    /**
     * Update a role
     */
    public function updateRole(Request $request, $id)
    {   
        
        $validator = Validator::make($request->all(), [
            'farm_id' => 'required|exists:farms,id',
            'name' => 'string|unique:roles,name',
            'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,id'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation Error', $validator->errors()->all());
        }

         $farm = Farm::findOrFail($request->farm_id);
         app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        if (!auth()->user()->can('manage roles' , 'api' ,$farm->id )) {
            return $this->sendError('You do not have permission to manage roles', [], 403);
        }

       

        try {
            DB::beginTransaction();

            // Verify all permissions exist
            foreach ($request->permissions as $permissionId) {
                $permission = Permission::find($permissionId);
                if (!$permission) {
                    throw new PermissionDoesNotExist("Permission with ID {$permissionId}");
                }
            }

            $role = Role::findOrFail($id);
            
            // Check if name exists for other roles
            if ($request->has('name') && $request->name !== $role->name) {
                $existingRole = Role::where('name', $request->name)
                    ->where('farm_id', $request->farm_id)
                    ->first();
                if ($existingRole) {
                    throw new \Exception('A role with this name already exists in this farm');
                }
                $role->update(['name' => $request->name]);
            }
            
            $role->syncPermissions($request->permissions);

            $this->RegisterEvent(
                farmId : $request->farm_id,
                eventType: 'role_updated',
                tableName: 'roles',
                tableId: $role->id
            );

            DB::commit();

            return $this->sendResponse($role->load('permissions'), 'Role updated successfully');
        } catch (PermissionDoesNotExist $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Error updating role', [$e->getMessage()], 500);
        }
    }

    /**
     * Add permissions to a role
     */
    public function addPermissionsToRole(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'farm_id' => 'required|exists:farms,id',
            'role_id' => 'required|exists:roles,id',
            'permission_ids' => 'required|array',
            'permission_ids.*' => 'exists:permissions,id'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation Error', $validator->errors()->all());
        }

        $farm = Farm::findOrFail($request->farm_id);

        // Set the team context (i.e., farm) before any permission checks
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        
        if (!auth()->user()->can('manage roles', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage roles', [], 403);
        }

        try {
            DB::beginTransaction();

            $role = Role::findOrFail($request->role_id);

            // Verify all permissions exist
            foreach ($request->permission_ids as $permissionId) {
                $permission = Permission::find($permissionId);
                if (!$permission) {
                    throw new PermissionDoesNotExist("Permission with ID {$permissionId} does not exist");
                }
            }

            // Add permissions to role (without removing existing ones)
            $role->givePermissionTo($request->permission_ids);

            $this->RegisterEvent(
                farmId: $farm->id,
                eventType: 'permissions_added_to_role',
                tableName: 'roles',
                tableId: $role->id
            );

            DB::commit();

            return $this->sendResponse($role->load('permissions'), 'Permissions added to role successfully');
        } catch (PermissionDoesNotExist $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Error adding permissions to role', [$e->getMessage()], 500);
        }
    }

    /**
     * Remove permissions from a role
     */
    public function removePermissionFromRole(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'farm_id' => 'required|exists:farms,id',
            'role_id' => 'required|exists:roles,id',
            'permission_ids' => 'required|array',
            'permission_ids.*' => 'exists:permissions,id'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation Error', $validator->errors()->all());
        }

        $farm = Farm::findOrFail($request->farm_id);

        // Set the team context (i.e., farm) before any permission checks
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        
        if (!auth()->user()->can('manage roles', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage roles', [], 403);
        }

        try {
            DB::beginTransaction();

            $role = Role::findOrFail($request->role_id);

            // Verify all permissions exist
            foreach ($request->permission_ids as $permissionId) {
                $permission = Permission::find($permissionId);
                if (!$permission) {
                    throw new PermissionDoesNotExist("Permission with ID {$permissionId} does not exist");
                }
            }

            // Remove permissions from role
            // Use manual detach to ensure team context is properly handled
            // revokePermissionTo may not work correctly with team context
            $role->permissions()->detach($request->permission_ids);
            
            // Clear the permission cache to ensure changes are reflected
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            
            // Ensure team context is set before reloading permissions
            app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

            $this->RegisterEvent(
                farmId: $farm->id,
                eventType: 'permissions_removed_from_role',
                tableName: 'roles',
                tableId: $role->id
            );

            DB::commit();

            // Reload role with permissions in the correct team context
            $role->refresh();
            return $this->sendResponse($role->load('permissions'), 'Permissions removed from role successfully');
        } catch (PermissionDoesNotExist $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Error removing permissions from role', [$e->getMessage()], 500);
        }
    }
    /**
     * Delete a role
     */
    public function deleteRole($farm , $id)
    {   
        $farm = Farm::findOrFail($request->farm_id);

        // Set the team context (i.e., farm) before any permission checks
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        
        if (!auth()->user()->can('manage roles' , 'api' , $farm->id)) {
            return $this->sendError('You do not have permission to manage roles', [], 403);
        }

        try {
            DB::beginTransaction();

            $role = Role::findOrFail($id);

            // Check if role is assigned to any users
            if ($role->users()->count() > 0) {
                return $this->sendError('Cannot delete role that is assigned to users', [], 422);
            }

            $this->RegisterEvent(

                eventType: 'role_deleted',
                tableName: 'roles',
                tableId: $role->id
            );

            $role->delete();

            DB::commit();

            return $this->sendResponse(null, 'Role deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Error deleting role', [$e->getMessage()], 500);
        }
    }

    /**
     * Assign role to user
     */
    public function assignRole(Request $request)
    {   
        
         app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        if (!auth()->user()->can('manage user roles' , 'api' , $farm->id)) {
            return $this->sendError('You do not have permission to manage user roles', [], 403);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'role_id' => 'required|exists:roles,id',
            'farm_id' => 'required|exists:farms,id'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation Error', $validator->errors()->all());
        }

        try {
            DB::beginTransaction();

            $user = User::findOrFail($request->user_id);
            $role = Role::findOrFail($request->role_id);

            $user->assignRole($role ,$farm_id );

            $this->RegisterEvent(
                eventType: 'role_assigned',
                tableName: 'users',
                tableId: $user->id
            );

            DB::commit();

            return $this->sendResponse($user->load('roles'), 'Role assigned successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Error assigning role', [$e->getMessage()], 500);
        }
    }

    /**
     * Remove role from user
     */
    public function removeRole(Request $request)
    {   
        
         app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        if (!auth()->user()->can('manage user roles' , 'api' , $farm->id)) {
            return $this->sendError('You do not have permission to manage user roles', [], 403);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'role_id' => 'required|exists:roles,id',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation Error', $validator->errors()->all());
        }

        try {
            DB::beginTransaction();

            $user = User::findOrFail($request->user_id);
            $role = Role::findOrFail($request->role_id);

            $user->removeRole($role, $farmId);

            $this->RegisterEvent(
                eventType: 'role_removed',
                tableName: 'users',
                tableId: $user->id
            );

            DB::commit();

            return $this->sendResponse($user->load('roles'), 'Role removed successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Error removing role', [$e->getMessage()], 500);
        }
    }

    public function  getUserPermissions($farm , $userId)
    {   
        
       
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm);
        if (!auth()->user()->can('view user permissions', 'api' , $farm)) {
            return $this->sendError('You do not have permission to view user permissions', [], 403);
        }
        $user = User::find($userId);
        
        if(!$user){
        return $this->sendError('User not found', [], 404);
        }
        
        if (!$user->farms()->where('farms.id', $farm)->exists()) {
            return $this->sendError('User does not belong to this farm', [], 403);
        }
        $permissions = $user->getPermissionsForFarm($farm);
        

        return $this->sendResponse(
            
            ['user' => $user ,'permissions' => $permissions] , 'User permissions retrieved successfully');
    }
    /**
     * Get the authenticated user's permission names for a farm.
     */
    public function getMyFarmPermissions($farm)
    {
        $user = auth()->user();

        if (!$user) {
            return $this->sendError('User not found', [], 404);
        }

        if (!$user->farms()->where('farms.id', $farm)->exists()) {
            return $this->sendError('User does not belong to this farm', [], 403);
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($farm);

        $permissions = [];
        $roles = $user->roles()->where('roles.farm_id', $farm)->with('permissions')->get();

        foreach ($roles as $role) {
            foreach ($role->permissions as $permission) {
                $permissions[] = $permission->name;
            }
        }

        $permissions = array_values(array_unique($permissions));

        return $this->sendResponse($permissions, 'User permissions retrieved successfully');
    }

    /**
     * Get user roles
     */
    public function getUserRoles($farm)
    {   
        
         app(PermissionRegistrar::class)->setPermissionsTeamId($farm);
        if (!auth()->user()->can('view user roles')) {
            return $this->sendError('You do not have permission to view user roles', [], 403);
        }

        $user = User::findOrFail($auth()->user()->id);
        $roles = $user->getRolesForFarm($farm);

        return $this->sendResponse($roles, 'User roles retrieved successfully');
    }

    /**
     * Sync user roles
     */
    public function syncUserRoles(Request $request)
    {       
        $validator = Validator::make($request->all(), [
            'farm_id' => 'required|exists:farms,id',
            'user_id' => 'required|exists:users,id',
            'role_ids' => 'required|array',
            'role_ids.*' => 'exists:roles,id'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation Error', $validator->errors()->all());
        }

        $farm = Farm::findOrFail($request->farm_id);

         app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        if (!auth()->user()->can('manage user roles', 'api' , $farm->id) ){
            return $this->sendError('You do not have permission to manage user roles', [], 403);
        }
        
        try {
            DB::beginTransaction();

            $user = User::findOrFail($request->user_id);
            $user->syncRoles($request->role_ids);

            $this->RegisterEvent(
                farmId : $farm_id,
                eventType: 'user_roles_synced',
                tableName: 'users',
                tableId: $user->id
            );

            DB::commit();

            return $this->sendResponse($user->load('roles'), 'User roles synced successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Error syncing user roles', [$e->getMessage()], 500);
        }
    }
}
