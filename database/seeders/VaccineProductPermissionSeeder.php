<?php

namespace Database\Seeders;

use App\Models\Farm;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VaccineProductPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Disable foreign key checks
        Schema::disableForeignKeyConstraints();

        // Get all farms
        $farms = Farm::all();

        foreach ($farms as $farm) {
            // Create vaccine product permissions for this farm
            $permissions = [
                'view vaccine products',
                'create vaccine products',
                'update vaccine products',
                'delete vaccine products',
            ];

            foreach ($permissions as $permissionName) {
                Permission::findOrCreate($permissionName, 'api', $farm->id);
            }

            // Get or create owner role for this farm
            $ownerRole = Role::findOrCreate('owner', 'api', $farm->id);

            // Assign all vaccine product permissions to owner role
            foreach ($permissions as $permissionName) {
                $permission = Permission::findByName($permissionName, 'api', $farm->id);
                if (!$ownerRole->hasPermissionTo($permission)) {
                    $ownerRole->givePermissionTo($permission);
                }
            }

            // Get farm owner (user who created the farm)
            $farmOwner = User::find($farm->created_by);
            
            if ($farmOwner) {
                // Assign owner role to farm owner if not already assigned
                if (!$farmOwner->hasRole($ownerRole, 'api', $farm->id)) {
                    $farmOwner->assignRole($ownerRole, 'api', $farm->id);
                }
            }
        }

        // Re-enable foreign key checks
        Schema::enableForeignKeyConstraints();

        $this->command->info('Vaccine product permissions created and assigned to farm owners successfully!');
    }
}
