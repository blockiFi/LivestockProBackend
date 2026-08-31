<?php

namespace App\Traits;

use App\Models\Farm;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

trait ManagesFarmRoles
{
    /**
     * Map high-level farm roles to permission names.
     *
     * @return array<string,array<int,string>>
     */
    protected function getRolesAndPermissions(): array
    {
        return [
            'owner' => [
                'view farm', 'update farm', 'delete farm', 'manage farm settings', 'manage users', 'manage billing',
                'view statistics', 'manage poultry houses', 'view flocks', 'update flocks', 'delete flock', 'manage flocks',
                'manage inventory', 'manage schedules',
                'view schedules', 'create schedules', 'update schedules', 'delete schedules',
                'view feeding schedules', 'create feeding schedules', 'update feeding schedules', 'delete feeding schedules',
                'view feeding schedule items', 'create feeding schedule items', 'update feeding schedule items', 'delete feeding schedule items',
                'view feeding batch schedules', 'create feeding batch schedules', 'update feeding batch schedules', 'delete feeding batch schedules',
                'view feeding batch schedule items', 'create feeding batch schedule items', 'update feeding batch schedule items', 'delete feeding batch schedule items',
                'view batch schedules', 'create batch schedules', 'update batch schedules', 'delete batch schedules',
                'manage sales',
                'view poultry types', 'manage poultry types',
                'view flock stages', 'manage flock stages',
                'view farm tasks', 'manage farm tasks', 'complete farm tasks', 'approve farm tasks',
            ],
            'manager' => [
                'view farm', 'update farm', 'manage farm settings', 'manage users',
                'view statistics', 'manage poultry houses', 'view flocks', 'update flocks', 'manage flocks',
                'manage inventory', 'manage schedules',
                'view schedules', 'create schedules', 'update schedules', 'delete schedules',
                'view feeding schedules', 'create feeding schedules', 'update feeding schedules', 'delete feeding schedules',
                'view feeding schedule items', 'create feeding schedule items', 'update feeding schedule items', 'delete feeding schedule items',
                'view feeding batch schedules', 'create feeding batch schedules', 'update feeding batch schedules', 'delete feeding batch schedules',
                'view feeding batch schedule items', 'create feeding batch schedule items', 'update feeding batch schedule items', 'delete feeding batch schedule items',
                'view batch schedules', 'create batch schedules', 'update batch schedules', 'delete batch schedules',
                'manage sales',
                'view poultry types', 'manage poultry types',
                'view flock stages', 'manage flock stages',
                'view farm tasks', 'manage farm tasks', 'complete farm tasks', 'approve farm tasks',
            ],
            'worker' => [
                'view farm', 'view statistics',
                'manage poultry houses', 'view flocks', 'manage flocks',
                'manage inventory', 'manage schedules',
                'view schedules', 'view feeding schedules', 'view feeding schedule items',
                'view feeding batch schedules', 'view feeding batch schedule items', 'view batch schedules',
                'view poultry types',
                'view flock stages',
                'view farm tasks', 'complete farm tasks',
            ],
        ];
    }

    /**
     * Add a role and its permissions to a farm.
     */
    protected function addFarmRole(Farm $farm, string $roleName, array $permissions): Role
    {
        // Create the role for this farm
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        $role = Role::firstOrCreate(
            ['name' => $roleName, 'guard_name' => 'api', 'farm_id' => $farm->id]
        );

        // Create and assign each permission
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'api');
        }

        // Sync all permissions to the role
        $role->syncPermissions($permissions);

        return $role;
    }

    /**
     * Create farm-specific roles and permissions for owner/manager/worker.
     */
    protected function createFarmRolesAndPermissions(Farm $farm): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        // Define all permissions
        $allPermissions = [
            // Farm Management
            'view farm',
            'create farm',
            'update farm',
            'delete farm',
            'manage farm settings',

            // Billing (owner only)
            'manage billing',

            // User Management
            'view users',
            'create users',
            'update users',
            'delete users',
            'manage users',
            'view user roles',
            'manage user roles',
            'view user permissions',
            'manage user permissions',

            // Role Management
            'view roles',
            'create roles',
            'update roles',
            'delete roles',
            'manage roles',
            'view permissions',
            'manage permissions',

            // Flock Management
            'view flocks',
            'create flocks',
            'update flocks',
            'delete flocks',
            'manage flocks',

            // Poultry House Management
            'view poultry houses',
            'create poultry houses',
            'update poultry houses',
            'delete poultry houses',
            'manage poultry houses',

            // Poultry Type Management
            'view poultry types',
            'create poultry types',
            'update poultry types',
            'delete poultry types',
            'manage poultry types',

            // Flock Stage Management
            'view flock stages',
            'create flock stages',
            'update flock stages',
            'delete flock stages',
            'manage flock stages',

            // Reports and Statistics
            'view reports',
            'generate reports',
            'view statistics',
            'export data',

            // Inventory Management
            'view inventory',
            'manage inventory',
            'view feed inventory',
            'manage feed inventory',
            'view feed types',
            'create feed types',
            'update feed types',
            'delete feed types',
            'view medication inventory',
            'manage medication inventory',
            'view vaccine inventory',
            'manage vaccine inventory',

            // Medication Management
            'view medications',
            'create medications',
            'update medications',
            'delete medications',
            'view medication products',
            'create medication products',
            'update medication products',
            'delete medication products',
            'view medication records',
            'create medication records',
            'update medication records',
            'delete medication records',

            // Vaccine Management
            'view vaccines',
            'create vaccines',
            'update vaccines',
            'delete vaccines',
            'view vaccine products',
            'create vaccine products',
            'update vaccine products',
            'delete vaccine products',
            'view vaccination records',
            'create vaccination records',
            'update vaccination records',
            'delete vaccination records',

            // Equipment Management
            'view equipment',
            'manage equipment',
            'view equipment financials',

            // Schedule Management
            'view schedules',
            'create schedules',
            'update schedules',
            'delete schedules',
            'manage schedules',
            'view feeding schedules',
            'create feeding schedules',
            'update feeding schedules',
            'delete feeding schedules',
            'view feeding schedule items',
            'create feeding schedule items',
            'update feeding schedule items',
            'delete feeding schedule items',
            'view feeding batch schedules',
            'create feeding batch schedules',
            'update feeding batch schedules',
            'delete feeding batch schedules',
            'view feeding batch schedule items',
            'create feeding batch schedule items',
            'update feeding batch schedule items',
            'delete feeding batch schedule items',
            'view batch schedules',
            'create batch schedules',
            'update batch schedules',
            'delete batch schedules',

            // Farm Task Management
            'view farm tasks',
            'manage farm tasks',
            'complete farm tasks',
            'approve farm tasks',

            // Record Management
            'view records',
            'create records',
            'update records',
            'delete records',
            'manage records',
            'view mortality records',
            'manage mortality records',
            'view weight records',
            'manage weight records',
            'view egg records',
            'manage egg records',

            // Customer Management
            'view customers',
            'create customers',
            'update customers',
            'delete customers',
            'manage customers',

            // Sales Management
            'view sales',
            'create sales',
            'update sales',
            'delete sales',
            'manage sales',
        ];

        // Ensure all permissions exist for this farm context
        foreach ($allPermissions as $permission) {
            Permission::findOrCreate($permission, 'api');
        }

        // Role permissions map
        $rolePermissions = [
            'owner' => $allPermissions,
            'manager' => array_filter($allPermissions, function ($permission) {
                // Managers can't manage users, billing, roles, or permissions or delete the farm
                return !in_array($permission, [
                    'manage users',
                    'manage billing',
                    'manage user roles',
                    'manage user permissions',
                    'manage roles',
                    'manage permissions',
                    'delete farm',
                ]);
            }),
            'worker' => array_filter($allPermissions, function ($permission) {
                // Workers have limited, mostly read-only permissions
                return in_array($permission, [
                    'view farm',
                    'view users',
                    'view roles',
                    'view permissions',
                    'view flocks',
                    'view poultry houses',
                    'view poultry types',
                    'view flock stages',
                    'view reports',
                    'view statistics',
                    'view inventory',
                    'view feed inventory',
                    'view feed types',
                    'view medication inventory',
                    'view vaccine inventory',
                    'view equipment',
                    'view schedules',
                    'view feeding schedules',
                    'view feeding schedule items',
                    'view feeding batch schedules',
                    'view feeding batch schedule items',
                    'view batch schedules',
                    'view farm tasks',
                    'complete farm tasks',
                    'view records',
                    'view mortality records',
                    'view weight records',
                    'view egg records',
                    'view customers',
                    'view sales',
                ]);
            }),
        ];

        foreach ($rolePermissions as $roleName => $permissions) {
            $this->addFarmRole($farm, $roleName, $permissions);
        }
    }
}

