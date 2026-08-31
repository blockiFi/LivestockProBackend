<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockComparativeReport;
use App\Models\FlockStage;
use App\Models\PoultryHouse;
use App\Models\PoultryType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FlockComparativeMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Farm $farm;
    protected string $token;
    protected PoultryType $poultryType;
    protected PoultryHouse $poultryHouse;
    protected FlockStage $flockStage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;
        $country = Country::factory()->create();
        $this->farm = Farm::factory()->create([
            'created_by' => $this->user->id,
            'country_id' => $country->id,
        ]);

        $permissions = [
            'view farm', 'update farm', 'delete farm', 'manage users',
            'view statistics', 'manage poultry houses', 'view flocks', 'manage flocks',
            'manage inventory', 'manage schedules', 'manage sales',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'api', $this->farm->id);
        }

        $ownerRole = Role::findOrCreate('owner', 'api', $this->farm->id);
        $ownerRole->givePermissionTo($permissions);
        $this->farm->users()->attach($this->user->id);
        $this->user->assignRole($ownerRole);

        $this->poultryType = PoultryType::factory()->create(['name' => 'Broiler']);
        $this->poultryHouse = PoultryHouse::factory()->create(['farm_id' => $this->farm->id]);
        $this->flockStage = FlockStage::factory()->create(['poultry_type_id' => $this->poultryType->id]);
    }

    public function test_unauthorized_user_cannot_access_comparative_metrics(): void
    {
        $target = $this->createFlock('active');
        $unauthorized = User::factory()->create();
        $token = $unauthorized->createToken('test-token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/farms/{$this->farm->id}/flocks/{$target->id}/metrics/comparative")
            ->assertStatus(403);
    }

    public function test_generates_comparative_report_with_completed_peers(): void
    {
        $target = $this->createFlock('active');
        $this->createFlock('completed');
        $this->createFlock('sold');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/flocks/{$target->id}/metrics/comparative");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'cached',
                    'peer_count',
                    'poultry_type',
                    'target_flock',
                    'peers',
                    'aggregates',
                    'highlights',
                ],
            ]);

        $this->assertSame(2, $response->json('data.peer_count'));
        $this->assertFalse($response->json('data.cached'));
        $this->assertDatabaseHas('flock_comparative_reports', [
            'farm_id' => $this->farm->id,
            'flock_id' => $target->id,
        ]);
    }

    public function test_returns_cached_report_on_second_get(): void
    {
        $target = $this->createFlock('active');
        $this->createFlock('completed');

        $first = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/flocks/{$target->id}/metrics/comparative")
            ->assertStatus(200);

        $this->assertFalse($first->json('data.cached'));

        $second = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/flocks/{$target->id}/metrics/comparative")
            ->assertStatus(200);

        $this->assertTrue($second->json('data.cached'));
        $this->assertEquals(1, FlockComparativeReport::where('flock_id', $target->id)->count());
    }

    public function test_post_regenerates_comparative_report(): void
    {
        $target = $this->createFlock('active');
        $this->createFlock('completed');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/flocks/{$target->id}/metrics/comparative")
            ->assertStatus(200);

        $original = FlockComparativeReport::where('flock_id', $target->id)->first();
        $this->assertNotNull($original);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flocks/{$target->id}/metrics/comparative")
            ->assertStatus(200);

        $this->assertFalse($response->json('data.cached'));
        $this->assertEquals(1, FlockComparativeReport::where('flock_id', $target->id)->count());
    }

    public function test_returns_empty_peer_state_when_no_completed_batches(): void
    {
        $target = $this->createFlock('active');
        $this->createFlock('active');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/flocks/{$target->id}/metrics/comparative")
            ->assertStatus(200);

        $this->assertSame(0, $response->json('data.peer_count'));
        $this->assertEmpty($response->json('data.peers'));
    }

    private function createFlock(string $status): Flock
    {
        return Flock::factory()->create([
            'farm_id' => $this->farm->id,
            'house_id' => $this->poultryHouse->id,
            'poultry_type_id' => $this->poultryType->id,
            'flock_stage_id' => $this->flockStage->id,
            'status' => $status,
            'actual_end_date' => in_array($status, ['sold', 'culled', 'completed'], true)
                ? now()->subDays(5)->toDateString()
                : null,
        ]);
    }
}
