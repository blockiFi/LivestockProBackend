<?php

namespace Database\Seeders;

use App\Models\Farm;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Grants vaccine / vaccination health permissions to existing farm owner & manager roles.
 * Run: php artisan db:seed --class=GrantVaccineHealthPermissionsSeeder
 */
class GrantVaccineHealthPermissionsSeeder extends Seeder
{
    /** @return array<int, string> */
    public static function permissionNames(): array
    {
        return [
            'view vaccines',
            'create vaccines',
            'update vaccines',
            'delete vaccines',
            'view vaccine products',
            'create vaccine products',
            'update vaccine products',
            'delete vaccine products',
            'view vaccine inventory',
            'manage vaccine inventory',
            'view vaccination records',
            'create vaccination records',
            'update vaccination records',
            'delete vaccination records',
            'manage inventory',
            'view inventory',
        ];
    }

    public function run(): void
    {
        $permissions = self::permissionNames();

        foreach (Farm::query()->get() as $farm) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

            foreach ($permissions as $name) {
                Permission::findOrCreate($name, 'api');
            }

            foreach (['owner', 'manager'] as $roleName) {
                $role = Role::query()
                    ->where('name', $roleName)
                    ->where('farm_id', $farm->id)
                    ->first();

                if (! $role) {
                    continue;
                }

                $role->givePermissionTo($permissions);
            }
        }

        $this->command?->info('Vaccine health permissions granted to owner/manager roles on all farms.');
    }
}
