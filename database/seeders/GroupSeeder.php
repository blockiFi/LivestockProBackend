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
                    'view farms',
                    'create farms',
                    'edit farms',
                    'delete farms',
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
                    'edit flocks',
                    'delete flocks',
                    'view flock records',
                    'create flock records',
                    'edit flock records',
                    'delete flock records',
                ]
            ],
            [
                'name' => 'Health Management',
                'description' => 'Permissions for medication, vaccination and health management',
                'color' => '#EF4444',
                'permissions' => [
                    'view medications',
                    'create medications',
                    'edit medications',
                    'delete medications',
                    'view medication records',
                    'create medication records',
                    'edit medication records',
                    'delete medication records',
                    'view medication inventory',
                    'create medication inventory',
                    'edit medication inventory',
                    'delete medication inventory',
                    'view vaccination records',
                    'create vaccination records',
                    'update vaccination records',
                    'delete vaccination records',
                ]
            ],
            [
                'name' => 'User Management',
                'description' => 'Permissions for managing users and access control',
                'color' => '#8B5CF6',
                'permissions' => [
                    'view users',
                    'create users',
                    'edit users',
                    'delete users',
                    'assign roles',
                    'manage permissions',
                ]
            ],
            [
                'name' => 'Reporting & Analytics',
                'description' => 'Permissions for viewing reports and analytics',
                'color' => '#F59E0B',
                'permissions' => [
                    'view reports',
                    'export reports',
                    'view analytics',
                    'view dashboard',
                ]
            ],
            [
                'name' => 'Feed Management',
                'description' => 'Permissions for feed and nutrition management',
                'color' => '#84CC16',
                'permissions' => [
                    'view feeds',
                    'create feeds',
                    'edit feeds',
                    'delete feeds',
                    'view feeding schedules',
                    'create feeding schedules',
                    'edit feeding schedules',
                    'delete feeding schedules',
                ]
            ],
        ];

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
                Permission::where('name', $permissionName)
                    ->update(['group_id' => $group->id]);
            }
        }
    }
}
