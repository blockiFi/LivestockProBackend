<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class GrantCustomerAccountPermissionsSeeder extends Seeder
{
    /**
     * Ensure customer-account wallet permissions exist and are granted to:
     * - every farm owner role
     * - any role that already has manage customers / view customers
     */
    public function run(): void
    {
        $names = [
            'view customer accounts',
            'top up customer accounts',
            'use customer account for payment',
            'adjust customer accounts',
            'refund customer accounts',
            'reverse customer account transactions',
        ];

        foreach ($names as $name) {
            Permission::findOrCreate($name, 'api');
        }

        $roles = Role::query()
            ->where('guard_name', 'api')
            ->where(function ($q) {
                $q->where('name', 'owner')
                    ->orWhereHas('permissions', function ($pq) {
                        $pq->whereIn('name', ['manage customers', 'view customers', 'create customers']);
                    });
            })
            ->get();

        $granted = 0;

        foreach ($roles as $role) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($role->farm_id);
            $role->givePermissionTo($names);
            $granted++;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info(
            "Granted customer account permissions to {$granted} role(s)."
        );
    }
}
