<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Group;
use App\Models\Permission;

class GroupPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clear existing group-permission relationships
        \DB::table('group_permission')->delete();
        \DB::table('groups')->delete();

        // Create groups
        $flockManagement = Group::create([
            'name' => 'Flock Management',
            'description' => 'Permissions related to managing flocks, poultry houses, and basic flock operations',
            'color' => '#3B82F6',
            'is_active' => true,
        ]);

        $healthManagement = Group::create([
            'name' => 'Health & Medication',
            'description' => 'Permissions for managing medication, vaccination, and health records',
            'color' => '#10B981',
            'is_active' => true,
        ]);

        $feedManagement = Group::create([
            'name' => 'Feed Management',
            'description' => 'Permissions for managing feed inventory, types, and feeding schedules',
            'color' => '#F59E0B',
            'is_active' => true,
        ]);

        $recordsReporting = Group::create([
            'name' => 'Records & Reporting',
            'description' => 'Permissions for managing records, reports, and data analysis',
            'color' => '#8B5CF6',
            'is_active' => true,
        ]);

        $salesCustomers = Group::create([
            'name' => 'Sales & Customers',
            'description' => 'Permissions for managing sales, customers, and commercial activities',
            'color' => '#06B6D4',
            'is_active' => true,
        ]);

        $systemAdmin = Group::create([
            'name' => 'System Administration',
            'description' => 'System-level permissions for managing users, roles, and farm settings',
            'color' => '#EF4444',
            'is_active' => true,
        ]);

        // Get all permissions
        $permissions = Permission::all()->keyBy('name');

        // Flock Management permissions
        $flockPermissions = [
            'view flocks' => 'View list of all flocks and their basic information',
            'create flocks' => 'Create new flocks and set up their initial parameters',
            'update flocks' => 'Edit existing flock information and settings',
            'delete flocks' => 'Remove flocks from the system (use with caution)',
            'manage flocks' => 'Full management access to all flock operations',
            'view poultry houses' => 'View poultry house information and layouts',
            'create poultry houses' => 'Create new poultry houses and facilities',
            'update poultry houses' => 'Modify existing poultry house configurations',
            'delete poultry houses' => 'Remove poultry houses from the system',
            'manage poultry houses' => 'Full management of poultry house operations',
            'view poultry types' => 'View different poultry breed and type information',
            'create poultry types' => 'Add new poultry breeds and types to the system',
            'update poultry types' => 'Modify existing poultry type information',
            'delete poultry types' => 'Remove poultry types from the system',
            'manage poultry types' => 'Full management of poultry type configurations',
            'view flock stages' => 'View different stages of flock development',
            'create flock stages' => 'Define new flock development stages',
            'update flock stages' => 'Modify existing flock stage parameters',
            'delete flock stages' => 'Remove flock stages from the system',
            'manage flock stages' => 'Full management of flock stage configurations',
        ];

        foreach ($flockPermissions as $permissionName => $description) {
            if (isset($permissions[$permissionName])) {
                $flockManagement->permissions()->attach($permissions[$permissionName]->id, [
                    'description' => $description
                ]);
            }
        }

        // Health & Medication permissions
        $healthPermissions = [
            'view medication records' => 'View medication administration records and history',
            'create medication records' => 'Record new medication administrations to flocks',
            'update medication records' => 'Edit existing medication records and dosages',
            'delete medication records' => 'Remove medication records from the system',
            'view medications' => 'View available medications and their properties',
            'create medications' => 'Add new medications to the system catalog',
            'update medications' => 'Modify medication information and properties',
            'delete medications' => 'Remove medications from the system catalog',
            'view medication products' => 'View detailed medication product information',
            'create medication products' => 'Add new medication products and brands',
            'update medication products' => 'Edit medication product details',
            'delete medication products' => 'Remove medication products from catalog',
            'view medication inventory' => 'View current medication stock levels',
            'manage medication inventory' => 'Full management of medication inventory',
            'view vaccines' => 'View available vaccines and vaccination schedules',
            'create vaccines' => 'Add new vaccines to the system',
            'update vaccines' => 'Modify vaccine information and schedules',
            'delete vaccines' => 'Remove vaccines from the system',
            'view vaccine inventory' => 'View current vaccine stock levels',
            'manage vaccine inventory' => 'Full management of vaccine inventory',
            'view mortality records' => 'View flock mortality data and statistics',
            'manage mortality records' => 'Full management of mortality record tracking',
        ];

        foreach ($healthPermissions as $permissionName => $description) {
            if (isset($permissions[$permissionName])) {
                $healthManagement->permissions()->attach($permissions[$permissionName]->id, [
                    'description' => $description
                ]);
            }
        }

        // Feed Management permissions
        $feedPermissions = [
            'view feed inventories' => 'View current feed stock levels and inventory',
            'create feed inventories' => 'Add new feed inventory entries',
            'update feed inventories' => 'Modify feed inventory quantities and details',
            'delete feed inventories' => 'Remove feed inventory entries',
            'view feed inventory' => 'General access to feed inventory information',
            'manage feed inventory' => 'Full management of feed inventory operations',
            'view feed types' => 'View different types of feed available',
            'create feed types' => 'Add new feed types and formulations',
            'update feed types' => 'Modify feed type specifications',
            'delete feed types' => 'Remove feed types from the system',
            'view feed usages' => 'View feed consumption records and patterns',
            'create feed usages' => 'Record new feed usage and consumption',
            'update feed usages' => 'Modify existing feed usage records',
            'delete feed usages' => 'Remove feed usage records',
            'view feeding schedules' => 'View planned feeding schedules and routines',
            'create feeding schedules' => 'Create new feeding schedules for flocks',
            'update feeding schedules' => 'Modify existing feeding schedules',
            'delete feeding schedules' => 'Remove feeding schedules',
            'view feeding schedule items' => 'View individual feeding schedule entries',
            'create feeding schedule items' => 'Add items to feeding schedules',
            'update feeding schedule items' => 'Modify feeding schedule items',
            'delete feeding schedule items' => 'Remove items from feeding schedules',
            'view batch schedules' => 'View batch feeding schedule information',
            'create batch schedules' => 'Create batch feeding schedules',
            'update batch schedules' => 'Modify batch feeding schedules',
            'delete batch schedules' => 'Remove batch feeding schedules',
            'view feeding batch schedule items' => 'View batch feeding schedule items',
            'create feeding batch schedule items' => 'Add items to batch feeding schedules',
            'update feeding batch schedule items' => 'Modify batch feeding schedule items',
            'delete feeding batch schedule items' => 'Remove batch feeding schedule items',
        ];

        foreach ($feedPermissions as $permissionName => $description) {
            if (isset($permissions[$permissionName])) {
                $feedManagement->permissions()->attach($permissions[$permissionName]->id, [
                    'description' => $description
                ]);
            }
        }

        // Records & Reporting permissions
        $recordsPermissions = [
            'view records' => 'View general record information and data',
            'create records' => 'Create new records and data entries',
            'update records' => 'Modify existing records and data',
            'delete records' => 'Remove records from the system',
            'manage records' => 'Full management of record operations',
            'view egg records' => 'View egg production records and statistics',
            'manage egg records' => 'Full management of egg production tracking',
            'view flock egg reports' => 'View detailed egg production reports by flock',
            'create flock egg reports' => 'Generate new egg production reports',
            'update flock egg reports' => 'Modify existing egg production reports',
            'delete flock egg reports' => 'Remove egg production reports',
            'view flock mortality reports' => 'View mortality analysis reports',
            'create flock mortality reports' => 'Generate mortality analysis reports',
            'update flock mortality reports' => 'Modify mortality reports',
            'delete flock mortality reports' => 'Remove mortality reports',
            'view weight records' => 'View flock weight tracking records',
            'manage weight records' => 'Full management of weight record tracking',
            'view flock weight reports' => 'View weight analysis reports by flock',
            'create flock weight reports' => 'Generate flock weight reports',
            'update flock weight reports' => 'Modify weight reports',
            'delete flock weight reports' => 'Remove weight reports',
            'view reports' => 'Access to general reporting functionality',
            'generate reports' => 'Generate custom reports and analytics',
            'view statistics' => 'View statistical analysis and dashboards',
            'export data' => 'Export data and reports to external formats',
            'view schedules' => 'View general scheduling information',
            'create schedules' => 'Create new schedules and planning entries',
            'update schedules' => 'Modify existing schedules',
            'delete schedules' => 'Remove schedules from the system',
            'manage schedules' => 'Full management of scheduling operations',
        ];

        foreach ($recordsPermissions as $permissionName => $description) {
            if (isset($permissions[$permissionName])) {
                $recordsReporting->permissions()->attach($permissions[$permissionName]->id, [
                    'description' => $description
                ]);
            }
        }

        // Sales & Customers permissions
        $salesPermissions = [
            'view sales' => 'View sales transactions and revenue data',
            'create sales' => 'Record new sales and transactions',
            'update sales' => 'Modify existing sales records',
            'delete sales' => 'Remove sales records from the system',
            'manage sales' => 'Full management of sales operations',
            'view customers' => 'View customer information and contact details',
            'create customers' => 'Add new customers to the system',
            'update customers' => 'Modify existing customer information',
            'delete customers' => 'Remove customers from the system',
            'manage customers' => 'Full management of customer relationships',
            'view inventory' => 'View general inventory levels and stock',
            'manage inventory' => 'Full management of inventory operations',
        ];

        foreach ($salesPermissions as $permissionName => $description) {
            if (isset($permissions[$permissionName])) {
                $salesCustomers->permissions()->attach($permissions[$permissionName]->id, [
                    'description' => $description
                ]);
            }
        }

        // System Administration permissions
        $adminPermissions = [
            'view users' => 'View user accounts and basic information',
            'create users' => 'Create new user accounts and profiles',
            'update users' => 'Modify existing user account information',
            'delete users' => 'Remove user accounts from the system',
            'manage users' => 'Full user account management capabilities',
            'view roles' => 'View system roles and their configurations',
            'create roles' => 'Create new roles and permission sets',
            'update roles' => 'Modify existing role configurations',
            'delete roles' => 'Remove roles from the system',
            'manage roles' => 'Full role management capabilities',
            'view permissions' => 'View system permissions and access controls',
            'manage permissions' => 'Full permission management capabilities',
            'view user roles' => 'View user-role assignments',
            'manage user roles' => 'Assign and manage user roles',
            'view user permissions' => 'View user permission assignments',
            'manage user permissions' => 'Assign and manage user permissions',
            'view farm' => 'View farm information and settings',
            'create farm' => 'Create new farm configurations',
            'update farm' => 'Modify farm settings and information',
            'delete farm' => 'Remove farm configurations',
            'manage farm settings' => 'Full farm configuration management',
        ];

        foreach ($adminPermissions as $permissionName => $description) {
            if (isset($permissions[$permissionName])) {
                $systemAdmin->permissions()->attach($permissions[$permissionName]->id, [
                    'description' => $description
                ]);
            }
        }

        $this->command->info('Permission groups created and populated with existing permissions!');
        $this->command->info('Groups created:');
        $this->command->info('- Flock Management: ' . $flockManagement->permissions->count() . ' permissions');
        $this->command->info('- Health & Medication: ' . $healthManagement->permissions->count() . ' permissions');
        $this->command->info('- Feed Management: ' . $feedManagement->permissions->count() . ' permissions');
        $this->command->info('- Records & Reporting: ' . $recordsReporting->permissions->count() . ' permissions');
        $this->command->info('- Sales & Customers: ' . $salesCustomers->permissions->count() . ' permissions');
        $this->command->info('- System Administration: ' . $systemAdmin->permissions->count() . ' permissions');
    }
}
