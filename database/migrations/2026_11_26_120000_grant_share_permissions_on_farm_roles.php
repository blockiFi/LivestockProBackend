<?php

use App\Models\Farm;
use App\Services\PermissionGroupAssignmentService;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds "share schedules" and "share feed products" and grants them to the
 * owner and manager roles of every farm. Uses givePermissionTo (not sync) so
 * any custom role edits a farm has made are kept.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['share schedules', 'share feed products'];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'api');
        }

        Farm::query()->each(function (Farm $farm) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

            Role::query()
                ->where('farm_id', $farm->id)
                ->whereIn('name', ['owner', 'manager'])
                ->get()
                ->each(fn (Role $role) => $role->givePermissionTo(self::PERMISSIONS));
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(PermissionGroupAssignmentService::class)->assignAll();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', self::PERMISSIONS)->where('guard_name', 'api')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
