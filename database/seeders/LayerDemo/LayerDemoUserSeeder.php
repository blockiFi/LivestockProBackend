<?php

namespace Database\Seeders\LayerDemo;

use App\Models\Country;
use App\Models\Farm;
use App\Models\PoultryHouse;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class LayerDemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $allPermissions = [
            'create farm', 'delete farm', 'manage farm settings', 'update farm', 'view farm',
            'create users', 'delete users', 'manage user permissions', 'manage user roles', 'manage users', 'update users', 'view user permissions', 'view user roles', 'view users',
            'create roles', 'delete roles', 'manage permissions', 'manage roles', 'update roles', 'view permissions', 'view roles',
            'create flocks', 'delete flocks', 'manage flocks', 'update flocks', 'view flocks',
            'create poultry houses', 'delete poultry houses', 'manage poultry houses', 'update poultry houses', 'view poultry houses',
            'create poultry types', 'delete poultry types', 'manage poultry types', 'update poultry types', 'view poultry types',
            'create flock stages', 'delete flock stages', 'manage flock stages', 'update flock stages', 'view flock stages',
            'export data', 'generate reports', 'view reports', 'view statistics',
            'manage feed inventory', 'manage inventory', 'manage medication inventory', 'manage vaccine inventory', 'view feed inventory', 'view inventory', 'view medication inventory', 'view vaccine inventory',
            'create schedules', 'delete schedules', 'manage schedules', 'update schedules', 'view schedules',
            'create schedule items', 'delete schedule items', 'update schedule items', 'view schedule items',
            'create records', 'delete records', 'manage records', 'manage mortality records', 'manage weight records', 'manage egg records', 'update records', 'view egg records', 'view mortality records', 'view records', 'view weight records',
            'create customers', 'delete customers', 'manage customers', 'update customers', 'view customers',
            'create sales', 'delete sales', 'manage sales', 'update sales', 'view sales',
            'create invoices', 'delete invoices', 'update invoices', 'view invoices',
            'create medications', 'delete medications', 'update medications', 'view medications',
            'create medication products', 'delete medication products', 'update medication products', 'view medication products',
            'create feed inventories', 'delete feed inventories', 'update feed inventories', 'view feed inventories',
            'create feed types', 'delete feed types', 'update feed types', 'view feed types',
            'create flock weight reports', 'delete flock weight reports', 'update flock weight reports', 'view flock weight reports',
            'create feed usages', 'delete feed usages', 'update feed usages', 'view feed usages',
            'create feeding batch schedule items', 'delete feeding batch schedule items', 'update feeding batch schedule items', 'view feeding batch schedule items',
            'create batch schedules', 'delete batch schedules', 'update batch schedules', 'view batch schedules',
            'create feeding schedule items', 'delete feeding schedule items', 'update feeding schedule items', 'view feeding schedule items',
            'create feeding schedules', 'delete feeding schedules', 'update feeding schedules', 'view feeding schedules',
            'create vaccines', 'delete vaccines', 'update vaccines', 'view vaccines',
        ];

        $managerPermissions = array_values(array_filter($allPermissions, fn ($p) => ! in_array($p, [
            'manage users', 'manage user roles', 'manage user permissions',
            'manage roles', 'manage permissions', 'delete farm',
        ], true)));

        $workerPermissions = array_values(array_filter($allPermissions, fn ($p) => in_array($p, [
            'view farm', 'view users', 'view roles', 'view permissions',
            'view flocks', 'view poultry houses', 'view poultry types', 'view flock stages',
            'view reports', 'view statistics',
            'view inventory', 'view feed inventory', 'view medication inventory', 'view vaccine inventory',
            'view schedules', 'view records', 'view mortality records', 'view weight records', 'view egg records',
            'view customers', 'view sales', 'view medications', 'view medication products',
            'view feed inventories', 'view feed types', 'view flock weight reports', 'view feed usages',
            'view feeding batch schedule items', 'view batch schedules', 'view feeding schedule items',
            'view feeding schedules', 'view vaccines',
        ], true)));

        $country = Country::query()->first() ?? Country::factory()->create();

        $farm = Farm::factory()->create([
            'name' => LayerDemoContext::FARM_NAME,
            'country_id' => $country->id,
        ]);

        PoultryHouse::factory()->create([
            'farm_id' => $farm->id,
            'name' => 'House A — Layers',
            'capacity' => 1500,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        $owner = User::factory()->create([
            'name' => 'Demo Owner',
            'email' => 'owner1@poultry.com',
        ]);
        $manager = User::factory()->create([
            'name' => 'Demo Manager',
            'email' => 'manager1@poultry.com',
        ]);
        $worker = User::factory()->create([
            'name' => 'Demo Worker',
            'email' => 'worker1@poultry.com',
        ]);

        $farm->users()->attach([$owner->id, $manager->id, $worker->id]);

        $roleMap = [
            'owner' => $allPermissions,
            'manager' => $managerPermissions,
            'worker' => $workerPermissions,
        ];

        foreach ($roleMap as $roleName => $permissions) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'api',
                'farm_id' => $farm->id,
            ]);
            $role->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        $owner->assignRole(Role::where('name', 'owner')->where('farm_id', $farm->id)->first());
        $manager->assignRole(Role::where('name', 'manager')->where('farm_id', $farm->id)->first());
        $worker->assignRole(Role::where('name', 'worker')->where('farm_id', $farm->id)->first());
    }
}
