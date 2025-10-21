<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Group;
use App\Models\Permission;

class UniquePermissionGroupSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // First, clear any existing group assignments
        Permission::query()->update(['group_id' => null]);
        
        // Clear existing groups to start fresh
        Group::query()->delete();

        // Create groups
        $groups = [
            'flock_management' => Group::create([
                'name' => 'Flock Management',
                'description' => 'Permissions for managing flocks, their stages, and daily operations',
                'color' => '#3B82F6',
                'is_active' => true,
            ]),
            'health_management' => Group::create([
                'name' => 'Health Management',
                'description' => 'Permissions for managing medications, vaccines, and health records',
                'color' => '#10B981',
                'is_active' => true,
            ]),
            'feed_management' => Group::create([
                'name' => 'Feed Management',
                'description' => 'Permissions for managing feed types, inventory, schedules, and usage',
                'color' => '#F59E0B',
                'is_active' => true,
            ]),
            'records_reports' => Group::create([
                'name' => 'Records & Reports',
                'description' => 'Permissions for managing records, generating reports, and viewing statistics',
                'color' => '#8B5CF6',
                'is_active' => true,
            ]),
            'sales_customers' => Group::create([
                'name' => 'Sales & Customers',
                'description' => 'Permissions for managing sales, customers, and egg records',
                'color' => '#EC4899',
                'is_active' => true,
            ]),
            'infrastructure' => Group::create([
                'name' => 'Infrastructure',
                'description' => 'Permissions for managing poultry houses, farm settings, and types',
                'color' => '#6B7280',
                'is_active' => true,
            ]),
            'system_admin' => Group::create([
                'name' => 'System Administration',
                'description' => 'Permissions for managing users, roles, permissions, and system settings',
                'color' => '#EF4444',
                'is_active' => true,
            ]),
            'schedule_management' => Group::create([
                'name' => 'Schedule Management',
                'description' => 'Permissions for managing feeding schedules, batch schedules, and general schedules',
                'color' => '#06B6D4',
                'is_active' => true,
            ]),
        ];

        // Define permission mappings - each permission goes to exactly one group
        $permissionMappings = [
            // Flock Management
            'flock_management' => [
                'create flocks', 'view flocks', 'update flocks', 'delete flocks',
                'manage flocks', 'create flock stages', 'view flock stages', 
                'update flock stages', 'delete flock stages', 'manage flock stages',
                'create flock weight reports', 'view flock weight reports', 'update flock weight reports', 
                'delete flock weight reports', 'manage weight records', 'view weight records',
                'create flock mortality reports', 'view flock mortality reports', 'update flock mortality reports',
                'delete flock mortality reports', 'manage mortality records', 'view mortality records',
                'create flock egg reports', 'view flock egg reports', 'update flock egg reports',
                'delete flock egg reports', 'manage egg records', 'view egg records'
            ],

            // Health Management
            'health_management' => [
                'create medications', 'view medications', 'update medications', 'delete medications',
                'create medication products', 'view medication products', 'update medication products', 'delete medication products',
                'create medication records', 'view medication records', 'update medication records', 'delete medication records',
                'manage medication inventory', 'view medication inventory',
                'create vaccines', 'view vaccines', 'update vaccines', 'delete vaccines',
                'manage vaccine inventory', 'view vaccine inventory'
            ],

            // Feed Management
            'feed_management' => [
                'create feed types', 'view feed types', 'update feed types', 'delete feed types',
                'create feed inventories', 'view feed inventories', 'update feed inventories', 'delete feed inventories',
                'manage feed inventory', 'view feed inventory',
                'create feed usages', 'view feed usages', 'update feed usages', 'delete feed usages',
                'create feeding schedules', 'view feeding schedules', 'update feeding schedules', 'delete feeding schedules',
                'create feeding schedule items', 'view feeding schedule items', 'update feeding schedule items', 'delete feeding schedule items'
            ],

            // Records & Reports
            'records_reports' => [
                'create records', 'view records', 'update records', 'delete records', 'manage records',
                'generate reports', 'view reports', 'view statistics', 'export data'
            ],

            // Sales & Customers
            'sales_customers' => [
                'create sales', 'view sales', 'update sales', 'delete sales', 'manage sales',
                'create customers', 'view customers', 'update customers', 'delete customers', 'manage customers'
            ],

            // Infrastructure
            'infrastructure' => [
                'create poultry houses', 'view poultry houses', 'update poultry houses', 'delete poultry houses', 'manage poultry houses',
                'create poultry types', 'view poultry types', 'update poultry types', 'delete poultry types', 'manage poultry types',
                'create farm', 'view farm', 'update farm', 'delete farm', 'manage farm settings'
            ],

            // System Administration
            'system_admin' => [
                'create users', 'view users', 'update users', 'delete users', 'manage users',
                'create roles', 'view roles', 'update roles', 'delete roles', 'manage roles',
                'view permissions', 'manage permissions', 'manage user permissions', 'manage user roles',
                'view user permissions', 'view user roles'
            ],

            // Schedule Management
            'schedule_management' => [
                'create schedules', 'view schedules', 'update schedules', 'delete schedules', 'manage schedules',
                'create batch schedules', 'view batch schedules', 'update batch schedules', 'delete batch schedules',
                'create feeding batch schedule items', 'view feeding batch schedule items', 'update feeding batch schedule items', 'delete feeding batch schedule items'
            ],
        ];

        // Assign permissions to groups
        foreach ($permissionMappings as $groupKey => $permissionNames) {
            $group = $groups[$groupKey];
            
            foreach ($permissionNames as $permissionName) {
                $permission = Permission::where('name', $permissionName)->first();
                if ($permission) {
                    $permission->update(['group_id' => $group->id]);
                    $this->command->info("Assigned '{$permissionName}' to '{$group->name}'");
                } else {
                    $this->command->warn("Permission '{$permissionName}' not found");
                }
            }
        }

        // Handle any remaining unassigned permissions
        $unassignedPermissions = Permission::whereNull('group_id')->get();
        if ($unassignedPermissions->count() > 0) {
            $this->command->info("\nUnassigned permissions:");
            foreach ($unassignedPermissions as $permission) {
                $this->command->line("- {$permission->name}");
                
                // Try to categorize remaining permissions
                if (str_contains($permission->name, 'inventory') || str_contains($permission->name, 'manage inventory')) {
                    $permission->update(['group_id' => $groups['feed_management']->id]);
                    $this->command->info("  -> Assigned to Feed Management");
                }
            }
        }

        $this->command->info("\nPermission group assignments completed!");
        
        // Display summary
        foreach ($groups as $group) {
            $count = $group->permissions()->count();
            $this->command->info("{$group->name}: {$count} permissions");
        }
    }
}
