<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Farm;
use App\Models\User;
use App\Services\FarmPermissionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A user's roles on one farm must not grant permissions on another farm.
 */
class FarmRoleScopingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $ownedFarm;
    private Farm $workerFarm;
    private string $ownerOnlyPermission;

    protected function setUp(): void
    {
        parent::setUp();

        $country = Country::factory()->create();
        $this->user = User::factory()->create();
        $other = User::factory()->create();

        $make = fn (string $name, User $creator) => Farm::factory()->create([
            'name' => $name, 'created_by' => $creator->id, 'country_id' => $country->id, 'status' => true,
        ]);
        $this->ownedFarm = $make('Owned Farm', $this->user);
        $this->workerFarm = $make('Worker Farm', $other);

        $sync = app(FarmPermissionSyncService::class);
        $sync->syncFarm($this->ownedFarm);
        $sync->syncFarm($this->workerFarm);

        $this->joinAs($this->ownedFarm, 'owner');
        $this->joinAs($this->workerFarm, 'worker');

        $ownerPerms = $this->rolePermissions($this->workerFarm, 'owner');
        $workerPerms = $this->rolePermissions($this->workerFarm, 'worker');
        $diff = array_values(array_diff($ownerPerms, $workerPerms));
        $this->assertNotEmpty($diff, 'Owner should have permissions a worker lacks');
        $this->ownerOnlyPermission = in_array('delete flocks', $diff, true) ? 'delete flocks' : $diff[0];

        $this->setFarm(null);
    }

    private function joinAs(Farm $farm, string $role): void
    {
        $farm->users()->attach($this->user->id);
        $this->setFarm($farm->id);
        $this->user->assignRole(Role::where('name', $role)->where('farm_id', $farm->id)->first());
    }

    private function rolePermissions(Farm $farm, string $role): array
    {
        return Role::where('name', $role)->where('farm_id', $farm->id)->first()
            ->permissions()->pluck('name')->all();
    }

    private function setFarm(?int $farmId): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($farmId);
        $this->user->unsetRelation('roles')->unsetRelation('permissions');
    }

    public function test_owner_role_on_another_farm_does_not_grant_permission_on_worker_farm(): void
    {
        $this->setFarm($this->workerFarm->id);

        $this->assertFalse($this->user->hasPermissionTo($this->ownerOnlyPermission));
        $this->assertTrue($this->user->hasRole('worker'));
        $this->assertFalse($this->user->hasRole('owner'));
    }

    public function test_owner_keeps_full_permissions_on_their_own_farm(): void
    {
        $this->setFarm($this->ownedFarm->id);

        $this->assertTrue($this->user->hasPermissionTo($this->ownerOnlyPermission));
        $this->assertTrue($this->user->hasRole('owner'));
        $this->assertFalse($this->user->hasRole('worker'));
    }

    public function test_roles_relation_only_lists_current_farm_roles(): void
    {
        $this->setFarm($this->workerFarm->id);
        $this->assertSame(['worker'], $this->user->roles()->pluck('roles.name')->all());

        $this->setFarm($this->ownedFarm->id);
        $this->assertSame(['owner'], $this->user->roles()->pluck('roles.name')->all());
    }

    public function test_without_farm_context_all_roles_are_listed(): void
    {
        $this->setFarm(null);

        $names = $this->user->roles()->pluck('roles.name')->sort()->values()->all();
        $this->assertSame(['owner', 'worker'], $names);
    }

    public function test_get_roles_for_farm_and_farm_role_middleware_query_work(): void
    {
        $this->setFarm($this->workerFarm->id);

        $this->assertSame(['worker'], $this->user->getRolesForFarm($this->workerFarm)->pluck('roles.name')->all());
        $this->assertTrue($this->user->roles()->where('roles.farm_id', $this->workerFarm->id)->exists());
    }

    public function test_sync_roles_on_one_farm_keeps_roles_on_other_farms(): void
    {
        $this->setFarm($this->workerFarm->id);
        $manager = Role::where('name', 'manager')->where('farm_id', $this->workerFarm->id)->first()
            ?? Role::where('name', 'worker')->where('farm_id', $this->workerFarm->id)->first();
        $this->user->syncRoles([$manager]);

        $this->setFarm($this->ownedFarm->id);
        $this->assertTrue($this->user->hasRole('owner'), 'Owner role on the other farm was removed');

        $this->setFarm($this->workerFarm->id);
        $this->assertSame([$manager->name], $this->user->roles()->pluck('roles.name')->all());
    }

    public function test_farm_route_checks_only_the_roles_on_that_farm(): void
    {
        // A throwaway route behind the real farm.member middleware, so the
        // farm context is set exactly as it is for every farm API route.
        \Illuminate\Support\Facades\Route::middleware(['api', 'auth:sanctum', 'farm.member'])
            ->get('/api/_test/farms/{farm}/can/{permission}', function ($farm, string $permission) {
                return response()->json(['allowed' => request()->user()->hasPermissionTo($permission)]);
            });

        $token = $this->user->createToken('t')->plainTextToken;
        $perm = rawurlencode($this->ownerOnlyPermission);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/_test/farms/{$this->workerFarm->id}/can/{$perm}")
            ->assertOk()->assertJson(['allowed' => false]);

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/_test/farms/{$this->ownedFarm->id}/can/{$perm}")
            ->assertOk()->assertJson(['allowed' => true]);
    }
}
