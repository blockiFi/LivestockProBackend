<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Group;
use App\Models\Permission;

class GroupSeeder extends Seeder
{
    /**
     * Run the database seeder.
     */
    public function run(): void
    {
        // Create permission groups
        $groups = [
            [
                'name' => 'Farm Management',
                'description' => 'Permissions related to farm operations and management',
                'color' => '#10B981',
                'permissions' => [
                    'view farm',
                    'create farm',
                    'update farm',
                    'delete farm',
                    'manage farm settings',
                ]
            ],
            [
                'name' => 'Flock Management',
                'description' => 'Permissions for managing poultry flocks',
                'color' => '#3B82F6',
                'permissions' => [
                    'view flocks',
                    'create flocks',
                    'update flocks',
                    'delete flocks',
                    'manage flocks',
                    'view records',
                    'create records',
                    'update records',
                    'delete records',
                    'view weight records',
                    'create flock weight reports',
                    'update flock weight reports',
                    'delete flock weight reports',
                    'view mortality records',
                    'create flock mortality reports',
                    'update flock mortality reports',
                    'delete flock mortality reports',
                    'view egg records',
                    'create flock egg reports',
                    'update flock egg reports',
                    'delete flock egg reports',
                ]
            ],
            [
                'name' => 'Health Management',
                'description' => 'Permissions for medication, vaccination and health management',
                'color' => '#EF4444',
                'permissions' => [
                    'view medications',
                    'create medications',
                    'update medications',
                    'delete medications',
                    'view medication records',
                    'create medication records',
                    'update medication records',
                    'delete medication records',
                    'view medication inventory',
                    'view vaccine inventory',
                    'view vaccination records',
                    'create vaccination records',
                    'update vaccination records',
                    'delete vaccination records',
                    'create vaccines',
                    'update vaccines',
                    'delete vaccines',
                    'view vaccines',
                ]
            ],
            [
                'name' => 'User Management',
                'description' => 'Permissions for managing users and access control',
                'color' => '#8B5CF6',
                'permissions' => [
                    'view users',
                    'create users',
                    'update users',
                    'delete users',
                    'manage users',
                    'view user roles',
                    'manage user roles',
                    'view user permissions',
                    'manage user permissions',
                    'view roles',
                    'create roles',
                    'update roles',
                    'delete roles',
                    'manage roles',
                    'view permissions',
                    'manage permissions',
                ]
            ],
            [
                'name' => 'Reporting & Analytics',
                'description' => 'Permissions for viewing reports and analytics',
                'color' => '#F59E0B',
                'permissions' => [
                    'view reports',
                    'generate reports',
                    'export data',
                    'view statistics',
                ]
            ],
            [
                'name' => 'Feed Management',
                'description' => 'Permissions for feed and nutrition management',
                'color' => '#84CC16',
                'permissions' => [
                    'view feed inventory',
                    'create feed inventories',
                    'update feed inventories',
                    'delete feed inventories',
                    'view feed usages',
                    'create feed usages',
                    'update feed usages',
                    'delete feed usages',
                    'view feeding schedules',
                    'create feeding schedules',
                    'update feeding schedules',
                    'delete feeding schedules',
                    'view feed types',
                    'create feed types',
                    'update feed types',
                    'delete feed types',
                ]
            ],
            [
                'name' => 'Inventory Management',
                'description' => 'Permissions for managing all inventory types',
                'color' => '#06B6D4',
                'permissions' => [
                    'view inventory',
                    'manage inventory',
                    'view medication inventory',
                    'manage medication inventory',
                    'view vaccine inventory',
                    'manage vaccine inventory',
                    'view feed inventory',
                    'manage feed inventory',
                ]
            ],
            [
                'name' => 'Schedule Management',
                'description' => 'Permissions for managing schedules',
                'color' => '#EC4899',
                'permissions' => [
                    'view schedules',
                    'create schedules',
                    'update schedules',
                    'delete schedules',
                    'manage schedules',
                    'view batch schedules',
                    'create batch schedules',
                    'update batch schedules',
                    'delete batch schedules',
                ]
            ],
            [
                'name' => 'Housing Management',
                'description' => 'Permissions for managing poultry houses',
                'color' => '#14B8A6',
                'permissions' => [
                    'view poultry houses',
                    'create poultry houses',
                    'update poultry houses',
                    'delete poultry houses',
                    'manage poultry houses',
                ]
            ],
            [
                'name' => 'Poultry Type Management',
                'description' => 'Permissions for managing poultry types',
                'color' => '#A855F7',
                'permissions' => [
                    'view poultry types',
                    'create poultry types',
                    'update poultry types',
                    'delete poultry types',
                    'manage poultry types',
                ]
            ],
            [
                'name' => 'Flock Stage Management',
                'description' => 'Permissions for managing flock stages',
                'color' => '#F97316',
                'permissions' => [
                    'view flock stages',
                    'create flock stages',
                    'update flock stages',
                    'delete flock stages',
                    'manage flock stages',
                ]
            ],
            [
                'name' => 'Customer Management',
                'description' => 'Permissions for managing customers',
                'color' => '#6366F1',
                'permissions' => [
                    'view customers',
                    'create customers',
                    'update customers',
                    'delete customers',
                    'manage customers',
                ]
            ],
            [
                'name' => 'Sales Management',
                'description' => 'Permissions for managing sales',
                'color' => '#10B981',
                'permissions' => [
                    'view sales',
                    'create sales',
                    'update sales',
                    'delete sales',
                    'manage sales',
                ]
            ],
            [
                'name' => 'Medication Product Management',
                'description' => 'Permissions for managing medication products',
                'color' => '#EF4444',
                'permissions' => [
                    'view medication products',
                    'create medication products',
                    'update medication products',
                    'delete medication products',
                ]
            ],
            [
                'name' => 'Schedule Item Management',
                'description' => 'Permissions for managing schedule items',
                'color' => '#EC4899',
                'permissions' => [
                    'create feeding schedule items',
                    'delete feeding schedule items',
                    'update feeding schedule items',
                    'view feeding schedule items',
                    'create feeding batch schedule items',
                    'delete feeding batch schedule items',
                    'update feeding batch schedule items',
                    'view feeding batch schedule items',
                ]
            ],
        ];

        // Create a catch-all group for any unassigned permissions
        $catchAllGroup = Group::updateOrCreate(
            ['name' => 'Other Permissions'],
            [
                'description' => 'Miscellaneous permissions not assigned to specific groups',
                'color' => '#6B7280',
                'is_active' => true,
            ]
        );

        foreach ($groups as $groupData) {
            $group = Group::updateOrCreate(
                ['name' => $groupData['name']],
                [
                    'description' => $groupData['description'],
                    'color' => $groupData['color'],
                    'is_active' => true,
                ]
            );

            // Assign permissions to the group (one-to-many relationship)
            foreach ($groupData['permissions'] as $permissionName) {
                // Use DB facade to ensure update works
                \DB::table('permissions')
                    ->where('name', $permissionName)
                    ->where('guard_name', 'api')
                    ->update(['group_id' => $group->id]);
            }
        }

        // Assign any remaining unassigned permissions to the catch-all group
        $unassignedCount = \DB::table('permissions')
            ->whereNull('group_id')
            ->where('guard_name', 'api')
            ->count();
        
        if ($unassignedCount > 0) {
            \DB::table('permissions')
                ->whereNull('group_id')
                ->where('guard_name', 'api')
                ->update(['group_id' => $catchAllGroup->id]);
            
            echo "Assigned {$unassignedCount} unassigned permissions to 'Other Permissions' group.\n";
        }
        
        // Final verification using DB facade
        $totalPermissions = \DB::table('permissions')->where('guard_name', 'api')->count();
        $assignedPermissions = \DB::table('permissions')
            ->where('guard_name', 'api')
            ->whereNotNull('group_id')
            ->count();
        $unassignedPermissions = $totalPermissions - $assignedPermissions;
        
        echo "Total permissions: {$totalPermissions}, Assigned: {$assignedPermissions}, Unassigned: {$unassignedPermissions}\n";
        
        if ($unassignedPermissions > 0) {
            echo "WARNING: {$unassignedPermissions} permissions still have null group_id!\n";
            // Show sample unassigned permissions
            $samples = \DB::table('permissions')
                ->where('guard_name', 'api')
                ->whereNull('group_id')
                ->limit(5)
                ->pluck('name');
            echo "Sample unassigned permissions: " . $samples->implode(', ') . "\n";
        }
    }
}
