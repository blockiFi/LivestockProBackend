<?php

namespace App\Traits;

use App\Models\Farm;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

trait ManagesFarmRoles
{
    /**
     * Map high-level farm roles to permission names.
     *
     * @return array<string, array<int, string>>
     */
    protected function getRolesAndPermissions(): array
    {
        return $this->getDefaultRolePermissionsMap();
    }

    /**
     * @return list<string>
     */
    protected function getAllFarmPermissions(): array
    {
        return [
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
            'view feed inventories',
            'create feed inventories',
            'update feed inventories',
            'delete feed inventories',
            'view feed types',
            'create feed types',
            'update feed types',
            'delete feed types',
            'view feed products',
            'create feed products',
            'update feed products',
            'delete feed products',
            'view feed usages',
            'create feed usages',
            'update feed usages',
            'delete feed usages',
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
            'view schedule items',
            'create schedule items',
            'update schedule items',
            'delete schedule items',
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
            'view flock weight reports',
            'create flock weight reports',
            'update flock weight reports',
            'delete flock weight reports',
            'view flock mortality reports',
            'create flock mortality reports',
            'update flock mortality reports',
            'delete flock mortality reports',
            'view flock egg reports',
            'create flock egg reports',
            'update flock egg reports',
            'delete flock egg reports',

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

            // Invoice Management
            'view invoices',
            'create invoices',
            'update invoices',
            'delete invoices',
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    protected function getDefaultRolePermissionsMap(): array
    {
        $allPermissions = $this->getAllFarmPermissions();

        return [
            'owner' => $allPermissions,
            'manager' => array_values(array_filter($allPermissions, function ($permission) {
                return ! in_array($permission, [
                    'manage users',
                    'manage billing',
                    'manage user roles',
                    'manage user permissions',
                    'manage roles',
                    'manage permissions',
                    'delete farm',
                ], true);
            })),
            'worker' => array_values(array_filter($allPermissions, function ($permission) {
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
                    'view feed inventories',
                    'view feed types',
                    'view feed products',
                    'view feed usages',
                    'view medication inventory',
                    'view vaccine inventory',
                    'view medications',
                    'view medication products',
                    'view vaccines',
                    'view vaccine products',
                    'view vaccination records',
                    'view medication records',
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
                    'view flock weight reports',
                    'view flock mortality reports',
                    'view flock egg reports',
                    'view customers',
                    'view sales',
                    'view invoices',
                ], true);
            })),
        ];
    }

    /**
     * Add a role and its permissions to a farm.
     */
    protected function addFarmRole(Farm $farm, string $roleName, array $permissions): Role
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        $role = Role::firstOrCreate(
            ['name' => $roleName, 'guard_name' => 'api', 'farm_id' => $farm->id]
        );

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'api');
        }

        $role->syncPermissions($permissions);

        return $role;
    }

    /**
     * Create farm-specific roles and permissions for owner/manager/worker.
     */
    protected function createFarmRolesAndPermissions(Farm $farm): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        foreach ($this->getAllFarmPermissions() as $permission) {
            Permission::findOrCreate($permission, 'api');
        }

        foreach ($this->getDefaultRolePermissionsMap() as $roleName => $permissions) {
            $this->addFarmRole($farm, $roleName, $permissions);
        }
    }
}
