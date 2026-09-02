<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    public function run()
    {
        $permissions = [
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
            'create schedule items', 'delete schedule items', 'update schedule items', 'view schedule items',
            // Record Management
            'create records', 'delete records', 'manage records', 'manage mortality records', 'manage weight records', 'manage egg records', 'update records', 'view egg records', 'view mortality records', 'view records', 'view weight records',
            // Customer Management
            'create customers', 'delete customers', 'manage customers', 'update customers', 'view customers',
            // Sales Management
            'create sales', 'delete sales', 'manage sales', 'update sales', 'view sales',
            // Invoice Management
            'create invoices', 'delete invoices', 'update invoices', 'view invoices',
            // Medication Management (from controllers)
            'create medications', 'delete medications', 'update medications', 'view medications',
            // Medication Record Management
            'create medication records', 'delete medication records', 'update medication records', 'view medication records',
            // Medication Product Management
            'create medication products', 'delete medication products', 'update medication products', 'view medication products',
            // Feed Inventory Management
            'create feed inventories', 'delete feed inventories', 'update feed inventories', 'view feed inventories',
            // Feed Type Management
            'create feed types', 'delete feed types', 'update feed types', 'view feed types',
            // Flock Weight Report Management
            'create flock weight reports', 'delete flock weight reports', 'update flock weight reports', 'view flock weight reports',
            // Flock Mortality Report Management
            'create flock mortality reports', 'delete flock mortality reports', 'update flock mortality reports', 'view flock mortality reports',
            // Flock Egg Report Management
            'create flock egg reports', 'delete flock egg reports', 'update flock egg reports', 'view flock egg reports',
            // Feed Usage Management
            'create feed usages', 'delete feed usages', 'update feed usages', 'view feed usages',
            // Feeding Batch Schedule Item Management
            'create feeding batch schedule items', 'delete feeding batch schedule items', 'update feeding batch schedule items', 'view feeding batch schedule items',
            // Feeding Batch Schedule Management
            'create feeding batch schedules', 'delete feeding batch schedules', 'update feeding batch schedules', 'view feeding batch schedules',
            // Batch Schedule Management
            'create batch schedules', 'delete batch schedules', 'update batch schedules', 'view batch schedules',
            // Feeding Schedule Item Management
            'create feeding schedule items', 'delete feeding schedule items', 'update feeding schedule items', 'view feeding schedule items',
            // Feeding Schedule Management
            'create feeding schedules', 'delete feeding schedules', 'update feeding schedules', 'view feeding schedules',
            // Vaccine Management
            'create vaccines', 'delete vaccines', 'update vaccines', 'view vaccines',
            'create vaccine products', 'delete vaccine products', 'update vaccine products', 'view vaccine products',
            // Vaccination Record Management
            'create vaccination records', 'delete vaccination records', 'update vaccination records', 'view vaccination records',
        ];
        $permissions = array_values(array_unique($permissions));
        sort($permissions);
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }
    }
} 