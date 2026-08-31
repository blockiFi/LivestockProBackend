<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Group;

class AssignAllPermissionsToGroups extends Seeder
{
    /**
     * Run the database seeder to assign ALL permissions to groups.
     */
    public function run(): void
    {
        // Get or create catch-all group
        $catchAllGroup = Group::updateOrCreate(
            ['name' => 'Other Permissions'],
            [
                'description' => 'Miscellaneous permissions not assigned to specific groups',
                'color' => '#6B7280',
                'is_active' => true,
            ]
        );

        // Get all groups
        $groups = Group::all()->keyBy('name');

        // Define permission mappings
        $permissionGroups = [
            'Farm Management' => [
                'view farm', 'create farm', 'update farm', 'delete farm', 'manage farm settings',
            ],
            'Flock Management' => [
                'view flocks', 'create flocks', 'update flocks', 'delete flocks', 'manage flocks',
                'view records', 'create records', 'update records', 'delete records', 'manage records',
                'view weight records', 'create flock weight reports', 'update flock weight reports', 'delete flock weight reports', 'manage weight records',
                'view mortality records', 'create flock mortality reports', 'update flock mortality reports', 'delete flock mortality reports', 'manage mortality records',
                'view egg records', 'create flock egg reports', 'update flock egg reports', 'delete flock egg reports', 'manage egg records',
            ],
            'Health Management' => [
                'view medications', 'create medications', 'update medications', 'delete medications',
                'view medication records', 'create medication records', 'update medication records', 'delete medication records',
                'view medication inventory', 'manage medication inventory',
                'view vaccine inventory', 'manage vaccine inventory',
                'view vaccination records', 'create vaccination records', 'update vaccination records', 'delete vaccination records',
                'create vaccines', 'update vaccines', 'delete vaccines', 'view vaccines',
            ],
            'User Management' => [
                'view users', 'create users', 'update users', 'delete users', 'manage users',
                'view user roles', 'manage user roles',
                'view user permissions', 'manage user permissions',
                'view roles', 'create roles', 'update roles', 'delete roles', 'manage roles',
                'view permissions', 'manage permissions',
            ],
            'Reporting & Analytics' => [
                'view reports', 'generate reports', 'export data', 'view statistics',
            ],
            'Feed Management' => [
                'view feed inventory', 'create feed inventories', 'update feed inventories', 'delete feed inventories', 'manage feed inventory',
                'view feed usages', 'create feed usages', 'update feed usages', 'delete feed usages',
                'view feeding schedules', 'create feeding schedules', 'update feeding schedules', 'delete feeding schedules',
                'view feed types', 'create feed types', 'update feed types', 'delete feed types',
            ],
            'Inventory Management' => [
                'view inventory', 'manage inventory',
                'view medication inventory', 'manage medication inventory',
                'view vaccine inventory', 'manage vaccine inventory',
                'view feed inventory', 'manage feed inventory',
            ],
            'Schedule Management' => [
                'view schedules', 'create schedules', 'update schedules', 'delete schedules', 'manage schedules',
                'view batch schedules', 'create batch schedules', 'update batch schedules', 'delete batch schedules',
            ],
            'Housing Management' => [
                'view poultry houses', 'create poultry houses', 'update poultry houses', 'delete poultry houses', 'manage poultry houses',
            ],
            'Poultry Type Management' => [
                'view poultry types', 'create poultry types', 'update poultry types', 'delete poultry types', 'manage poultry types',
            ],
            'Flock Stage Management' => [
                'view flock stages', 'create flock stages', 'update flock stages', 'delete flock stages', 'manage flock stages',
            ],
            'Customer Management' => [
                'view customers', 'create customers', 'update customers', 'delete customers', 'manage customers',
            ],
            'Sales Management' => [
                'view sales', 'create sales', 'update sales', 'delete sales', 'manage sales',
            ],
            'Medication Product Management' => [
                'view medication products', 'create medication products', 'update medication products', 'delete medication products',
            ],
            'Schedule Item Management' => [
                'create feeding schedule items', 'delete feeding schedule items', 'update feeding schedule items', 'view feeding schedule items',
                'create feeding batch schedule items', 'delete feeding batch schedule items', 'update feeding batch schedule items', 'view feeding batch schedule items',
            ],
        ];

        // Assign permissions to their groups using direct DB queries
        foreach ($permissionGroups as $groupName => $permissions) {
            if (!isset($groups[$groupName])) {
                echo "Group '{$groupName}' not found, skipping...\n";
                continue;
            }
            
            $groupId = $groups[$groupName]->id;
            $updated = DB::table('permissions')
                ->whereIn('name', $permissions)
                ->where('guard_name', 'api')
                ->update(['group_id' => $groupId]);
            
            echo "Assigned {$updated} permissions to '{$groupName}' group.\n";
        }

        // Assign all remaining unassigned permissions to catch-all group
        $unassignedCount = DB::table('permissions')
            ->whereNull('group_id')
            ->where('guard_name', 'api')
            ->count();
        
        if ($unassignedCount > 0) {
            $updated = DB::table('permissions')
                ->whereNull('group_id')
                ->where('guard_name', 'api')
                ->update(['group_id' => $catchAllGroup->id]);
            
            echo "Assigned {$updated} unassigned permissions to 'Other Permissions' group.\n";
        }

        // Final verification
        $totalPermissions = DB::table('permissions')->where('guard_name', 'api')->count();
        $assignedPermissions = DB::table('permissions')
            ->where('guard_name', 'api')
            ->whereNotNull('group_id')
            ->count();
        $unassignedPermissions = $totalPermissions - $assignedPermissions;
        
        echo "\n=== FINAL RESULTS ===\n";
        echo "Total permissions: {$totalPermissions}\n";
        echo "Assigned: {$assignedPermissions}\n";
        echo "Unassigned: {$unassignedPermissions}\n";
        
        if ($unassignedPermissions > 0) {
            echo "\nWARNING: {$unassignedPermissions} permissions still have null group_id!\n";
            $samples = DB::table('permissions')
                ->where('guard_name', 'api')
                ->whereNull('group_id')
                ->limit(10)
                ->pluck('name');
            echo "Sample unassigned permissions:\n";
            foreach ($samples as $name) {
                echo "  - {$name}\n";
            }
        } else {
            echo "\nSUCCESS: All permissions have been assigned to groups!\n";
        }
    }
}
