<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class GrantBillingPermissionSeeder extends Seeder
{
    /**
     * Give the owner role on every existing farm the new billing permission so
     * they can reach the billing page without re-creating their roles.
     */
    public function run(): void
    {
        Permission::findOrCreate('manage billing', 'api');

        $ownerRoles = Role::where('name', 'owner')->get();

        foreach ($ownerRoles as $role) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($role->farm_id);
            $role->givePermissionTo('manage billing');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info("Granted 'manage billing' to {$ownerRoles->count()} owner role(s).");
    }
}
