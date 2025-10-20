<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Farm;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

class UserSeeder extends Seeder
{
    public function run()
    {
        // Clean up global roles and related pivots to avoid farm_id=null issues
       
        
        $allPermissions = $permissions = [
            // Farm Management
            'create farm', 'delete farm', 'manage farm settings', 'update farm', 'view farm',
            // User Management
            'create users', 'delete users', 'manage user permissions', 'manage user roles', 'manage users', 'update users', 'view user permissions', 'view user roles', 'view users',
            // Role Management
            'create roles', 'delete roles', 'manage permissions', 'manage roles', 'update roles', 'view permissions', 'view roles',
            // Flock Management
            'create flocks', 'delete flocks', 'manage flocks', 'update flocks', 'view flocks',
            // Poultry House Management
            'create poultry houses', 'delete poultry houses', 'manage poultry houses', 'update poultry houses', 'view poultry houses',
            // Poultry Type Management
            'create poultry types', 'delete poultry types', 'manage poultry types', 'update poultry types', 'view poultry types',
            // Flock Stage Management
            'create flock stages', 'delete flock stages', 'manage flock stages', 'update flock stages', 'view flock stages',
            // Reports and Statistics
            'export data', 'generate reports', 'view reports', 'view statistics',
            // Inventory Management
            'manage feed inventory', 'manage inventory', 'manage medication inventory', 'manage vaccine inventory', 'view feed inventory', 'view inventory', 'view medication inventory', 'view vaccine inventory',
            // Schedule Management
            'create schedules', 'delete schedules', 'manage schedules', 'update schedules', 'view schedules',
            // Record Management
            'create records', 'delete records', 'manage records', 'manage mortality records', 'manage weight records', 'manage egg records', 'update records', 'view egg records', 'view mortality records', 'view records', 'view weight records',
            // Customer Management
            'create customers', 'delete customers', 'manage customers', 'update customers', 'view customers',
            // Sales Management
            'create sales', 'delete sales', 'manage sales', 'update sales', 'view sales',
            // Medication Management (from controllers)
            'create medications', 'delete medications', 'update medications', 'view medications',
            // Medication Product Management
            'create medication products', 'delete medication products', 'update medication products', 'view medication products',
            // Feed Inventory Management
            'create feed inventories', 'delete feed inventories', 'update feed inventories', 'view feed inventories',
            // Feed Type Management
            'create feed types', 'delete feed types', 'update feed types', 'view feed types',
            // Flock Weight Report Management
            'create flock weight reports', 'delete flock weight reports', 'update flock weight reports', 'view flock weight reports',
            // Feed Usage Management
            'create feed usages', 'delete feed usages', 'update feed usages', 'view feed usages',
            // Feeding Batch Schedule Item Management
            'create feeding batch schedule items', 'delete feeding batch schedule items', 'update feeding batch schedule items', 'view feeding batch schedule items',
            // Batch Schedule Management
            'create batch schedules', 'delete batch schedules', 'update batch schedules', 'view batch schedules',
            // Feeding Schedule Item Management
            'create feeding schedule items', 'delete feeding schedule items', 'update feeding schedule items', 'view feeding schedule items',
            // Feeding Schedule Management
            'create feeding schedules', 'delete feeding schedules', 'update feeding schedules', 'view feeding schedules',
            // Vaccine Management
            'create vaccines', 'delete vaccines', 'update vaccines', 'view vaccines',
        ];
        $rolePermissions = [
            'owner' => $allPermissions, // Owner has all permissions
            'manager' => array_filter($allPermissions, function($permission) {
                // Managers can't manage users, roles, or permissions, or delete farm
                return !in_array($permission, [
                    'manage users',
                    'manage user roles',
                    'manage user permissions',
                    'manage roles',
                    'manage permissions',
                    'delete farm'
                ]);
            }),
            'worker' => array_filter($allPermissions, function($permission) {
                // Workers have only basic view and essential non-destructive permissions
                return in_array($permission, [
                    // Farm
                    'view farm',
                    // Users
                    'view users',
                    // Roles & Permissions
                    'view roles', 'view permissions',
                    // Flocks & Poultry
                    'view flocks', 'view poultry houses', 'view poultry types', 'view flock stages',
                    // Reports & Statistics
                    'view reports', 'view statistics',
                    // Inventory
                    'view inventory', 'view feed inventory', 'view medication inventory', 'view vaccine inventory',
                    // Schedules
                    'view schedules',
                    // Records
                    'view records', 'view mortality records', 'view weight records', 'view egg records',
                    // Customers & Sales
                    'view customers', 'view sales',
                    // Medication
                    'view medications',
                    // Medication Products
                    'view medication products',
                    // Feed Inventories
                    'view feed inventories',
                    // Feed Types
                    'view feed types',
                    // Flock Weight Reports
                    'view flock weight reports',
                    // Feed Usages
                    'view feed usages',
                    // Feeding Batch Schedule Items
                    'view feeding batch schedule items',
                    // Batch Schedules
                    'view batch schedules',
                    // Feeding Schedule Items
                    'view feeding schedule items',
                    // Feeding Schedules
                    'view feeding schedules',
                    // Vaccines
                    'view vaccines',
                ]);
            })
        ];

        // Create farms (for demo, let's create 5 farms)
        for ($i = 1; $i <= 5; $i++) {
            $farm = \App\Models\Farm::factory()->create();

            // Set the permissions team context for this farm
            app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

            // Create users for this farm
            $owner = \App\Models\User::factory()->create([
                'name' => 'Owner User ' . $i,
                'email' => 'owner' . $i . '@poultry.com',
            ]);
            $manager = \App\Models\User::factory()->create([
                'name' => 'Manager User ' . $i,
                'email' => 'manager' . $i . '@poultry.com',
            ]);
            $worker = \App\Models\User::factory()->create([
                'name' => 'Worker User ' . $i,
                'email' => 'worker' . $i . '@poultry.com',
            ]);

            // Attach users to farm
            $farm->users()->attach([$owner->id, $manager->id, $worker->id]);

            // Create roles for this farm and assign permissions
            $roleModels = [];
            foreach ($rolePermissions as $role => $permissions) {
                
                $roleModel = Role::firstOrCreate([
                'name' => $role,
                'guard_name' => 'api',
                'farm_id' => $farm->id,
                ]);
                if ($roleModel->farm_id === null) {
                    dd('Role created with null farm_id', $role, $farm->id, $roleModel->toArray());
                }
                $roleModel->syncPermissions($permissions);
                $roleModels[$role] = $roleModel;
            }

            $ownerRole = Role::where('name', 'owner')
           ->where('farm_id', $farm->id)
           ->first();
            $managerRole = Role::where('name', 'manager')
            ->where('farm_id', $farm->id)
            ->first();
            $workerRole = Role::where('name', 'worker')
            ->where('farm_id', $farm->id)
            ->first();
            app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
            $owner->assignRole($ownerRole);
            $manager->assignRole($managerRole);
            $worker->assignRole($workerRole);

            
        }
       
    }
} 