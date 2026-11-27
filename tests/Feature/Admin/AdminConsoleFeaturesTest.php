<?php

namespace Tests\Feature\Admin;

use App\Models\Farm;
use App\Models\User;
use App\Services\FarmPermissionSyncService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AdminConsoleFeaturesTest extends AdminTestCase
{
    public function test_global_search_finds_farms_and_users(): void
    {
        $this->farm->update(['name' => 'Sunrise Poultry']);

        $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/search?q=Sunrise')
            ->assertOk()
            ->assertJsonPath('data.farms.0.id', $this->farm->id);

        $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/search?q=' . urlencode($this->regularUser->email))
            ->assertOk()
            ->assertJsonPath('data.users.0.id', $this->regularUser->id);
    }

    public function test_dashboard_insights_and_alerts(): void
    {
        $res = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/dashboard/insights?days=14')
            ->assertOk()
            ->assertJsonStructure(['data' => ['period', 'trends' => ['new_farms', 'new_users', 'active_users', 'revenue'], 'series', 'attention', 'activity']]);

        $this->assertCount(14, $res->json('data.series'));

        $this->withHeaders($this->adminHeaders())->getJson('/api/admin/alerts')->assertOk()->assertJsonStructure(['data' => ['items']]);
    }

    public function test_farm_list_sorts_and_filters(): void
    {
        $country = $this->farm->country_id;
        Farm::factory()->create(['name' => 'Aardvark Farm', 'created_by' => $this->regularUser->id, 'country_id' => $country, 'status' => false]);

        $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/farms?sort=name&direction=asc')
            ->assertOk()
            ->assertJsonPath('data.data.0.name', 'Aardvark Farm');

        $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/farms?status=suspended')
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    public function test_bulk_suspend_farms_is_logged(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->postJson('/api/admin/farms/bulk', ['action' => 'suspend', 'ids' => [$this->farm->id]])
            ->assertOk()
            ->assertJsonPath('data.updated', 1);

        $this->assertFalse((bool) $this->farm->fresh()->status);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'farm.bulk.suspend']);
    }

    public function test_readonly_admin_cannot_bulk_or_export(): void
    {
        $readonly = $this->makeAdmin('readonly');

        $this->withHeaders($this->headersAs($readonly))
            ->postJson('/api/admin/farms/bulk', ['action' => 'suspend', 'ids' => [$this->farm->id]])
            ->assertStatus(403);

        $this->withHeaders($this->headersAs($readonly))
            ->get('/api/admin/users/export')
            ->assertStatus(403);
    }

    public function test_csv_exports(): void
    {
        foreach (['/api/admin/farms/export', '/api/admin/users/export', '/api/admin/audit-logs/export', '/api/admin/revenue/subscriptions/export'] as $url) {
            $res = $this->withHeaders($this->adminHeaders())->get($url);
            $res->assertOk();
            $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
            $this->assertNotEmpty(trim($res->streamedContent()), $url);
        }

        $csv = $this->withHeaders($this->adminHeaders())->get('/api/admin/users/export')->streamedContent();
        $this->assertStringContainsString($this->regularUser->email, $csv);
    }

    public function test_csv_export_neutralises_formulas(): void
    {
        $this->regularUser->update(['name' => '=HYPERLINK("http://x")']);

        $csv = $this->withHeaders($this->adminHeaders())->get('/api/admin/users/export')->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_user_filters_and_bulk_verify(): void
    {
        $this->regularUser->forceFill(['email_verified_at' => null])->save();

        $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/users?verified=0&type=farm')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $this->regularUser->id);

        $this->withHeaders($this->adminHeaders())
            ->postJson('/api/admin/users/bulk', ['action' => 'verify', 'ids' => [$this->regularUser->id]])
            ->assertOk();

        $this->assertNotNull($this->regularUser->fresh()->email_verified_at);
    }

    public function test_bulk_deactivate_skips_own_account(): void
    {
        $res = $this->withHeaders($this->adminHeaders())
            ->postJson('/api/admin/users/bulk', ['action' => 'deactivate', 'ids' => [$this->admin->id, $this->regularUser->id]])
            ->assertOk();

        $this->assertSame([$this->regularUser->id], $res->json('data.ids'));
        $this->assertNull($this->admin->fresh()->deactivated_at);
    }

    public function test_platform_role_matrix_reflects_route_middleware(): void
    {
        $matrix = collect($this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/access/platform-roles')
            ->assertOk()
            ->json('data.matrix'));

        $delete = $matrix->first(fn ($r) => $r['method'] === 'DELETE' && $r['uri'] === '/farms/{farm}');
        $this->assertSame('super_admin', $delete['min_role']);

        $list = $matrix->first(fn ($r) => $r['method'] === 'GET' && $r['uri'] === '/farms');
        $this->assertSame('readonly', $list['min_role']);
    }

    public function test_farm_roles_and_member_role_change_stay_on_that_farm(): void
    {
        $sync = app(FarmPermissionSyncService::class);
        $sync->syncFarm($this->farm);

        $other = Farm::factory()->create(['created_by' => $this->admin->id, 'country_id' => $this->farm->country_id, 'status' => true]);
        $sync->syncFarm($other);

        $member = User::factory()->create();
        $this->farm->users()->attach($member->id);
        $other->users()->attach($member->id);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($this->farm->id);
        $member->assignRole(Role::where('name', 'worker')->where('farm_id', $this->farm->id)->first());
        $registrar->setPermissionsTeamId($other->id);
        $member->unsetRelation('roles');
        $member->assignRole(Role::where('name', 'owner')->where('farm_id', $other->id)->first());
        $registrar->setPermissionsTeamId(null);

        $roles = $this->withHeaders($this->adminHeaders())
            ->getJson("/api/admin/farms/{$this->farm->id}/roles")
            ->assertOk()
            ->json('data.roles');
        $this->assertNotEmpty($roles);

        $manager = Role::where('farm_id', $this->farm->id)->where('name', '!=', 'worker')->where('name', '!=', 'owner')->first()
            ?? Role::where('farm_id', $this->farm->id)->where('name', 'owner')->first();

        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/admin/farms/{$this->farm->id}/members/{$member->id}/role", ['role_id' => $manager->id])
            ->assertOk()
            ->assertJsonPath('data.new.0', $manager->name);

        // The owner role on the other farm is untouched.
        $this->assertDatabaseHas('model_has_roles', ['model_id' => $member->id, 'farm_id' => $other->id]);
        $this->assertDatabaseHas('model_has_roles', ['model_id' => $member->id, 'farm_id' => $this->farm->id, 'role_id' => $manager->id]);

        // A role from another farm is refused.
        $foreign = Role::where('farm_id', $other->id)->first();
        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/admin/farms/{$this->farm->id}/members/{$member->id}/role", ['role_id' => $foreign->id])
            ->assertStatus(422);
    }

    public function test_role_permissions_can_be_edited_by_super_admin_only(): void
    {
        app(FarmPermissionSyncService::class)->syncFarm($this->farm);
        $worker = Role::where('farm_id', $this->farm->id)->where('name', 'worker')->first();
        $perms = $worker->permissions()->pluck('name')->take(2)->values()->all();

        $this->withHeaders($this->headersAs($this->makeAdmin('support')))
            ->putJson("/api/admin/farms/{$this->farm->id}/roles/{$worker->id}/permissions", ['permissions' => $perms])
            ->assertStatus(403);

        $this->withHeaders($this->headersAs($this->admin))
            ->putJson("/api/admin/farms/{$this->farm->id}/roles/{$worker->id}/permissions", ['permissions' => $perms])
            ->assertOk();

        $this->assertEqualsCanonicalizing($perms, $worker->fresh()->permissions()->pluck('name')->all());
    }

    public function test_revenue_access_audit_and_system_endpoints(): void
    {
        foreach ([
            '/api/admin/revenue/overview',
            '/api/admin/revenue/subscriptions',
            '/api/admin/access/farm-roles',
            '/api/admin/audit-logs/facets',
            '/api/admin/system/queue',
            '/api/admin/system/failed-jobs',
            '/api/admin/notifications/broadcasts',
            '/api/admin/team',
            '/api/admin/analytics/compare',
            "/api/admin/farms/{$this->farm->id}/invitations",
        ] as $url) {
            $this->withHeaders($this->adminHeaders())->getJson($url)->assertOk();
        }
    }

    public function test_broadcast_appears_in_history(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->postJson('/api/admin/notifications/broadcast', ['title' => 'Maintenance', 'body' => 'Tonight at 10'])
            ->assertOk();

        $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/notifications/broadcasts')
            ->assertOk()
            ->assertJsonPath('data.data.0.new_values.title', 'Maintenance')
            ->assertJsonPath('data.data.0.new_values.body', 'Tonight at 10');
    }
}
