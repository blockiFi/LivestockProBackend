<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Http\Controllers\Api\ApiController;
use App\Services\FarmDeletionService;
use App\Services\FarmEntitlementService;
use App\Traits\HasFarmPermissions;
use App\Traits\RegisterEvents;
use App\Traits\ManagesFarmRoles;

use DB;
use Spatie\Permission\PermissionRegistrar;


class FarmController extends ApiController
{
    use HasFarmPermissions, RegisterEvents, ManagesFarmRoles;
    /**
     * Get all farms for the authenticated user
     */
    public function getUserFarms()
    {
        
        $user = auth()->user();
        $farms = $user->farms()->with(['country'])->get();
       
        return $this->sendResponse($farms, 'Farms retrieved successfully');
    }

    /**
     * Create a new farm
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'address' => 'required|string',
            'country_id' => 'required|exists:countries,id',
            'state' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'website' => 'nullable|url|max:255',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'established_date' => 'nullable|date',
            'size_hectares' => 'nullable|numeric|min:0',
            'registration_number' => 'nullable|string|max:255|unique:farms,registration_number',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation Error', $validator->errors()->all());
        }

        $data = $validator->validated();
        $data['created_by'] = auth()->id();
        
        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')->store('farm-logos', 'public');
            $data['logo'] = $logoPath;
        }

        $farm = Farm::create($data);
        
        // Attach the farm to the creating user
        $farm->users()->attach(auth()->id());

        // Create farm-specific roles and permissions
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        $this->createFarmRolesAndPermissions($farm);

        // Assign owner role to the creating user
        $ownerRole = Role::where('name', 'owner')
            ->where('farm_id', $farm->id)
            ->first();
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        auth()->user()->assignRole($ownerRole);

        // Start the farm on a trial so billing enforcement has something to read.
        app(FarmEntitlementService::class)->subscription($farm);

        // Register the farm creation event
        $this->RegisterEvent(
            farmId: $farm->id,
            eventType: 'farm_creation',
            tableName: 'farm',
            tableId: $farm->id
        );
        
        return $this->sendResponse($farm, 'Farm created successfully', 201);
    }

    /**
     * Get a specific farm
     */
    public function show($id)
    {
        $farm = Farm::findOrFail($id);

        // Check if user has permission to view farm
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        if (!auth()->user()->can('view farm', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view this farm', [], 403);
        }
        return $this->sendResponse($farm, 'Farm retrieved successfully');
    }

    /**
     * Update a farm
     */
    public function update(Request $request, $id)
    {
        $farm = Farm::findOrFail($id);

        // Check if user has permission to update farm
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        if (!auth()->user()->can('update farm', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to update this farm', [], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'address' => 'sometimes|required|string',
            'phone' => 'sometimes|required|string|max:20',
            'email' => 'sometimes|required|email|max:255',
            'country_id' => 'sometimes|required|exists:countries,id',
            'state' => 'sometimes|required|string|max:255',
            'city' => 'sometimes|required|string|max:255',
            'postal_code' => 'sometimes|required|string|max:20',
            'website' => 'nullable|url|max:255',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'established_date' => 'sometimes|required|date',
            'size_hectares' => 'sometimes|required|numeric|min:0',
            'registration_number' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('farms')->ignore($id)
            ],
            'status' => 'sometimes|required|in:active,inactive'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation Error', $validator->errors()->all());
        }

        $data = $validator->validated();

        if ($request->hasFile('logo')) {
            if ($farm->logo) {
                Storage::disk('public')->delete($farm->logo);
            }
            $logoPath = $request->file('logo')->store('farm-logos', 'public');
            $data['logo'] = $logoPath;
        }

        $farm->update($data);
        $this->RegisterEvent(
            farmId: $farm->id,
            eventType: 'farm_update',
            tableName: 'farm',
            tableId: $farm->id
        );
            
       
        return $this->sendResponse($farm, 'Farm updated successfully');
    }

    /**
     * Delete a farm
     */
    public function destroy($id)
    {
        $farm = Farm::findOrFail($id);

        // Check if user has permission to delete farm
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        if (!auth()->user()->can('delete farm', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to delete this farm', [], 403);
        }

        // Register the deletion event before deleting the farm
        $this->RegisterEvent(
            farmId: $farm->id,
            eventType: 'farm_deletion',
            tableName: 'farm',
            tableId: $farm->id
        );

        if ($farm->logo) {
            Storage::disk('public')->delete($farm->logo);
        }

        app(FarmDeletionService::class)->cleanupSpatieTeamData($farm->id);
        $farm->delete();

        return $this->sendResponse(null, 'Farm deleted successfully');
    }

    /**
     * Add a user to a farm
     */
    public function addUser(Request $request, $id)
    {
        $farm = Farm::findOrFail($id);
    
        // Set the team context (i.e., farm) before any permission checks
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
    
        // Ensure authenticated user has permission scoped to this farm
        if (!auth()->user()->can('manage users', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage users', [], 403);
        }

        // Validate input
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'role' => 'required|string|in:owner,manager,worker',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation Error', $validator->errors()->all());
        }

        $user = User::findOrFail($request->user_id);
        $roleName = $request->role;

        // Check if user already belongs to this farm
        if ($farm->users()->where('user_id', $user->id)->exists()) {
            return $this->sendError('User already belongs to this farm', [], 409);
        }

        // Attach user to the farm
        $farm->users()->attach($user->id);

        $this->RegisterEvent(
            farmId: $farm->id,
            eventType: 'user_added',
            tableName: 'user',
            tableId: $user->id
        );

        // Ensure roles and permissions are defined for this role
        $rolesAndPermissions = $this->getRolesAndPermissions();
        if (!array_key_exists($roleName, $rolesAndPermissions)) {
            return $this->sendError('Invalid role definition in permissions map', [], 422);
        }

        // Attempt to fetch role scoped to this farm
        $role = Role::where('name', $roleName)
            ->where('farm_id', $farm->id)
            ->first();

        // If role doesn't exist, create it with correct permissions
        if (!$role) {
            $permissions = $rolesAndPermissions[$roleName];
            $role = $this->addFarmRole($farm, $roleName, $permissions);

            // Extra check: ensure role was created properly
            if (!$role) {
                return $this->sendError('Failed to create role', [], 500);
            }
        }

        // Team-scoped role assignment
        // Assign the role using the actual Role model (not just the string)
        
        if ($role->name !== $request->role) {
            return $this->sendError('Role mismatch during assignment', [], 500);
        }
        
        $user->assignRole($roleName);
        
        
      
        return $this->sendResponse(null, 'User added to farm successfully');
    }

    /**
     * Remove a user from a farm
     */
    public function removeUser(Request $request, $id)
    {
        $farm = Farm::findOrFail($id);
    
        // Set the team context (i.e., farm) before any permission checks
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
    
        // Ensure authenticated user has permission scoped to this farm
        if (!auth()->user()->can('manage users', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage users', [], 403);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation Error', $validator->errors()->all());
        }

        $user = User::findOrFail($request->user_id);

        // Get all roles related to this farm
        $farmRoles = Role::where('farm_id', $farm->id)->get();

        // Remove each farm-specific role from the user
        foreach ($farmRoles as $role) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
            $user->removeRole($role);
        }

        // Detach user from farm
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
     * Get farm statistics
     */
    public function getStatistics($id)
    {
        $farm = Farm::findOrFail($id);

        // Check if user has permission to view statistics
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        if (!auth()->user()->can('view statistics' , 'api' , $farm->id)) {
            return $this->sendError('You do not have permission to view statistics', [], 403);
        }

        $statistics = [
            'total_poultry_houses' => (int) $farm->poultryHouses()->count(),
            'total_flocks' => (int) $farm->flocks()->count(),
            'total_customers' => (int) $farm->customers()->count(),
            'total_sales' => (float) $farm->salesRecords()->sum('total_price'),
            'active_schedules' => (int) $farm->batchSchedules()->where('status', 'active')->count(),
            'total_medication_inventory' => (float) $farm->poultryMedicationInventories()->sum('quantity'),
            'total_vaccine_inventory' => (float) $farm->poultryVaccineInventories()->sum('quantity'),
            'total_feed_inventory' => (float) $farm->poultryFeedInventories()->sum('quantity')
        ];

        return $this->sendResponse($statistics, 'Farm statistics retrieved successfully');
    }

    // Role / permission helpers are provided by ManagesFarmRoles trait

    /**
     * Get all users for a specific farm with their roles and permissions
     */
    public function getFarmUsers($id)
    {
        $farm = Farm::findOrFail($id);
    
        // Set team context (important for spatie/permission multi-tenancy)
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
    
        // Check permission to view users in the current team context
        if (!auth()->user()->can('view users', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view farm users', [], 403);
        }
    
        // Eager load users and filter roles by farm_id from the correct table (roles)
        $users = $farm->users()
            ->with(['roles' => function ($query) use ($farm) {
                $query->where('roles.farm_id', $farm->id)
                      ->with('permissions'); // eager load permissions for each role
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
}    