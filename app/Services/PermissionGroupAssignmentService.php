<?php

namespace App\Services;

use App\Models\Group;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

class PermissionGroupAssignmentService
{
    /**
     * @return array<string, list<string>>
     */
    public function permissionGroupMap(): array
    {
        return [
            'Farm Management' => [
                'view farm', 'create farm', 'update farm', 'delete farm', 'manage farm settings',
                'manage billing',
            ],
            'Flock Management' => [
                'view flocks', 'create flocks', 'update flocks', 'delete flocks', 'manage flocks',
                'view records', 'create records', 'update records', 'delete records', 'manage records',
                'view weight records', 'create flock weight reports', 'update flock weight reports', 'delete flock weight reports', 'manage weight records',
                'view mortality records', 'create flock mortality reports', 'update flock mortality reports', 'delete flock mortality reports', 'manage mortality records',
                'view egg records', 'create flock egg reports', 'update flock egg reports', 'delete flock egg reports', 'manage egg records',
            ],
            'Farm Task Management' => [
                'view farm tasks', 'manage farm tasks', 'complete farm tasks', 'approve farm tasks',
            ],
            'Health Management' => [
                'view medications', 'create medications', 'update medications', 'delete medications',
                'view medication records', 'create medication records', 'update medication records', 'delete medication records',
                'view medication inventory', 'manage medication inventory',
                'view vaccine inventory', 'manage vaccine inventory',
                'view vaccination records', 'create vaccination records', 'update vaccination records', 'delete vaccination records',
                'create vaccines', 'update vaccines', 'delete vaccines', 'view vaccines',
                'view vaccine products', 'create vaccine products', 'update vaccine products', 'delete vaccine products',
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
                'view feeding batch schedules', 'create feeding batch schedules', 'update feeding batch schedules', 'delete feeding batch schedules',
            ],
            'Schedule Item Management' => [
                'view schedule items', 'create schedule items', 'update schedule items', 'delete schedule items',
                'create feeding schedule items', 'delete feeding schedule items', 'update feeding schedule items', 'view feeding schedule items',
                'create feeding batch schedule items', 'delete feeding batch schedule items', 'update feeding batch schedule items', 'view feeding batch schedule items',
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
            'Invoice Management' => [
                'view invoices', 'create invoices', 'update invoices', 'delete invoices',
            ],
            'Equipment Management' => [
                'view equipment', 'manage equipment', 'view equipment financials',
            ],
            'Medication Product Management' => [
                'view medication products', 'create medication products', 'update medication products', 'delete medication products',
            ],
        ];
    }

    public function assignAll(): int
    {
        $catchAll = Group::updateOrCreate(
            ['name' => 'Other Permissions'],
            [
                'description' => 'Miscellaneous permissions not assigned to specific groups',
                'color' => '#6B7280',
                'is_active' => true,
            ]
        );

        $groups = Group::all()->keyBy('name');
        $assigned = 0;

        foreach ($this->permissionGroupMap() as $groupName => $permissions) {
            $group = $groups->get($groupName) ?? Group::updateOrCreate(
                ['name' => $groupName],
                [
                    'description' => "{$groupName} permissions",
                    'color' => '#6B7280',
                    'is_active' => true,
                ]
            );

            $assigned += DB::table('permissions')
                ->whereIn('name', $permissions)
                ->where('guard_name', 'api')
                ->update(['group_id' => $group->id]);
        }

        $assigned += DB::table('permissions')
            ->whereNull('group_id')
            ->where('guard_name', 'api')
            ->update(['group_id' => $catchAll->id]);

        return $assigned;
    }

    public function totalPermissionCount(): int
    {
        return Permission::query()->where('guard_name', 'api')->count();
    }
}
