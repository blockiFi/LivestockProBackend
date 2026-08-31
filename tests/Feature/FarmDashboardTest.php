<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockDailyRecord;
use App\Models\FlockExpenditure;
use App\Models\FlockSale;
use App\Models\FlockStage;
use App\Models\Permission;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryFeedType;
use App\Models\PoultryFeedUsage;
use App\Models\PoultryHouse;
use App\Models\PoultryMortalityReport;
use App\Models\PoultryType;
use App\Models\Role;
use App\Models\SalesRecord;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FarmDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private Flock $flock;
    private string $token;
    private PoultryFeedType $feedType;
    private PoultryFeedInventory $inventory;
    private PoultryType $poultryType;

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

        $permissions = collect([
            'view statistics',
            'view flocks',
            'view feed inventories',
            'view feed inventory',
            'view medication inventory',
            'view vaccine inventory',
        ])->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']));

        $ownerRole = Role::create([
            'name' => 'owner',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $ownerRole->givePermissionTo($permissions);

        $this->farm->users()->attach($this->user->id);
        $this->user->assignRole($ownerRole);

        $this->poultryType = PoultryType::factory()->create(['name' => 'Layer']);
        $flockStage = FlockStage::factory()->create(['poultry_type_id' => $this->poultryType->id]);
        $house = PoultryHouse::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_type_id' => $this->poultryType->id,
        ]);

        $this->flock = Flock::factory()->create([
            'farm_id' => $this->farm->id,
            'house_id' => $house->id,
            'poultry_type_id' => $this->poultryType->id,
            'flock_stage_id' => $flockStage->id,
            'quantity' => 100,
            'status' => 'active',
            'arrival_date' => Carbon::now()->subDays(20)->toDateString(),
        ]);

        $this->feedType = PoultryFeedType::create([
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_type_id' => $this->poultryType->id,
            'name' => 'Grower',
            'description' => 'Test',
        ]);

        $this->inventory = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 500,
            'available_quantity' => 500,
            'unit_cost' => 10,
            'status' => 'available',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_kpis_use_actual_quantity_after_mortality(): void
    {
        PoultryMortalityReport::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'poultry_type_id' => $this->poultryType->id,
            'date' => Carbon::now()->subDays(2)->toDateString(),
            'mortality_count' => 10,
            'bird_count' => 100,
            'mortality_percentage' => 10,
            'recorded_by' => $this->user->id,
        ]);

        $this->assertEquals(90, $this->flock->fresh()->actual_quantity);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/dashboard?preset=30d");

        $response->assertStatus(200)
            ->assertJsonPath('data.kpis.active_birds', 90)
            ->assertJsonPath('data.kpis.total_birds', 90)
            ->assertJsonPath('data.kpis.active_flocks', 1)
            ->assertJsonPath('data.kpis.total_flocks', 1);

        $distributionBirds = collect($response->json('data.flock_distribution'))->sum('birds');
        $this->assertEquals(90, $distributionBirds);
    }

    public function test_daily_feed_cost_is_not_prorated(): void
    {
        $dayA = Carbon::now()->subDays(5)->toDateString();
        $dayB = Carbon::now()->subDays(3)->toDateString();

        PoultryFeedUsage::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_inventory_id' => $this->inventory->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'flock_id' => $this->flock->id,
            'quantity' => 10,
            'unit_cost' => 5,
            'usage_date' => $dayA,
            'created_by' => $this->user->id,
        ]);

        PoultryFeedUsage::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_inventory_id' => $this->inventory->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'flock_id' => $this->flock->id,
            'quantity' => 20,
            'unit_cost' => 8,
            'usage_date' => $dayB,
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/dashboard?preset=30d");

        $response->assertStatus(200);
        $series = collect($response->json('data.series'));

        $pointA = $series->firstWhere('date', $dayA);
        $pointB = $series->firstWhere('date', $dayB);

        $this->assertEquals(10.0, (float) $pointA['feed_kg']);
        $this->assertEquals(50.0, (float) $pointA['feed_cost']); // 10 * 5
        $this->assertEquals(20.0, (float) $pointB['feed_kg']);
        $this->assertEquals(160.0, (float) $pointB['feed_cost']); // 20 * 8
        $this->assertEquals(210.0, (float) $response->json('data.kpis.feed_cost'));
        $this->assertEquals(30.0, (float) $response->json('data.kpis.feed_kg'));
    }

    public function test_series_is_gapless_and_zero_filled(): void
    {
        $start = Carbon::now()->subDays(6)->toDateString();
        $end = Carbon::now()->toDateString();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/dashboard?start_date={$start}&end_date={$end}");

        $response->assertStatus(200);
        $series = $response->json('data.series');
        $this->assertCount(7, $series);

        $dates = collect($series)->pluck('date')->all();
        $expected = [];
        $cursor = Carbon::parse($start);
        while ($cursor->lte(Carbon::parse($end))) {
            $expected[] = $cursor->toDateString();
            $cursor->addDay();
        }
        $this->assertEquals($expected, $dates);

        foreach ($series as $point) {
            $this->assertArrayHasKey('feed_kg', $point);
            $this->assertArrayHasKey('feed_cost', $point);
            $this->assertArrayHasKey('eggs', $point);
            $this->assertArrayHasKey('mortality', $point);
            $this->assertArrayHasKey('revenue', $point);
            $this->assertArrayHasKey('cost', $point);
            $this->assertArrayHasKey('net_profit', $point);
        }
    }

    public function test_previous_period_window_is_correct(): void
    {
        $start = Carbon::now()->subDays(9)->toDateString();
        $end = Carbon::now()->toDateString();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/dashboard?start_date={$start}&end_date={$end}");

        $response->assertStatus(200)
            ->assertJsonPath('data.meta.period_days', 10);

        $prevStart = Carbon::parse($start)->subDays(10)->toDateString();
        $prevEnd = Carbon::parse($start)->subDay()->toDateString();

        $this->assertEquals($prevStart, $response->json('data.meta.previous_start_date'));
        $this->assertEquals($prevEnd, $response->json('data.meta.previous_end_date'));
        $this->assertArrayHasKey('feed_cost', $response->json('data.previous_period'));
        $this->assertArrayHasKey('revenue', $response->json('data.previous_period'));
    }

    public function test_revenue_and_profit_match_seeded_sales_and_expenditures(): void
    {
        $date = Carbon::now()->subDays(2)->toDateString();

        FlockSale::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'quantity' => 5,
            'unit_price' => 1000,
            'total_amount' => 5000,
            'date' => $date,
            'created_by' => $this->user->id,
        ]);

        FlockExpenditure::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'category' => 'feed',
            'amount' => 1500,
            'currency' => 'NGN',
            'description' => 'Feed',
            'date' => $date,
            'source_type' => 'manual',
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/dashboard?preset=30d");

        $response->assertStatus(200)
            ->assertJsonPath('data.kpis.revenue', 5000)
            ->assertJsonPath('data.kpis.cost', 1500)
            ->assertJsonPath('data.kpis.net_profit', 3500)
            ->assertJsonPath('data.kpis.margin_percent', 70);

        $categories = collect($response->json('data.cost_by_category'));
        $this->assertEquals(1500.0, (float) $categories->firstWhere('category', 'feed')['total_cost']);
    }

    public function test_dashboard_revenue_includes_product_sales(): void
    {
        $date = Carbon::now()->subDays(2)->toDateString();

        FlockSale::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'quantity' => 5,
            'unit_price' => 1000,
            'total_amount' => 5000,
            'date' => $date,
            'created_by' => $this->user->id,
        ]);

        SalesRecord::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'type' => 'egg',
            'quantity' => 100,
            'unit_price' => 20,
            'total_amount' => 2000,
            'date' => $date,
            'payment_status' => 'paid',
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/dashboard?preset=30d");

        $response->assertStatus(200)
            ->assertJsonPath('data.kpis.revenue', 7000)
            ->assertJsonPath('data.kpis.net_profit', 7000);
    }

    public function test_lifetime_preset_includes_older_activity(): void
    {
        $oldDate = Carbon::now()->subMonths(8)->toDateString();

        PoultryFeedUsage::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_inventory_id' => $this->inventory->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'flock_id' => $this->flock->id,
            'quantity' => 42,
            'unit_cost' => 10,
            'usage_date' => $oldDate,
            'created_by' => $this->user->id,
        ]);

        FlockSale::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'quantity' => 2,
            'unit_price' => 500,
            'total_amount' => 1000,
            'date' => $oldDate,
            'created_by' => $this->user->id,
        ]);

        $recentOnly = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/dashboard?preset=30d");

        $recentOnly->assertStatus(200)
            ->assertJsonPath('data.kpis.feed_kg', 0)
            ->assertJsonPath('data.kpis.revenue', 0);

        $lifetime = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/dashboard?preset=lifetime");

        $lifetime->assertStatus(200)
            ->assertJsonPath('data.kpis.feed_kg', 42)
            ->assertJsonPath('data.kpis.revenue', 1000)
            ->assertJsonPath('data.meta.start_date', $oldDate);
    }

    public function test_mortality_rate_uses_birds_at_risk_for_sold_flocks(): void
    {
        $this->flock->update(['status' => 'sold']);

        FlockDailyRecord::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'date' => Carbon::now()->subDays(3)->toDateString(),
            'mortality' => 10,
            'mortality_count' => 10,
            'recorded_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/dashboard?preset=lifetime");

        $response->assertStatus(200)
            ->assertJsonPath('data.kpis.active_birds', 0)
            ->assertJsonPath('data.kpis.mortality', 10)
            ->assertJsonPath('data.kpis.mortality_rate_percent', 10);
    }

    public function test_dashboard_excludes_active_broiler_batches_from_net_profit(): void
    {
        $date = Carbon::now()->subDays(2)->toDateString();

        $broilerType = PoultryType::factory()->create(['name' => 'Broiler']);
        $broilerStage = FlockStage::factory()->create(['poultry_type_id' => $broilerType->id]);
        $broilerHouse = PoultryHouse::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_type_id' => $broilerType->id,
        ]);

        $activeBroiler = Flock::factory()->create([
            'farm_id' => $this->farm->id,
            'house_id' => $broilerHouse->id,
            'poultry_type_id' => $broilerType->id,
            'flock_stage_id' => $broilerStage->id,
            'quantity' => 500,
            'status' => 'active',
            'name' => 'Active Broiler Batch',
        ]);

        FlockSale::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $activeBroiler->id,
            'quantity' => 50,
            'unit_price' => 1000,
            'total_amount' => 50000,
            'date' => $date,
            'created_by' => $this->user->id,
        ]);

        FlockExpenditure::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $activeBroiler->id,
            'category' => 'feed',
            'amount' => 20000,
            'currency' => 'NGN',
            'description' => 'Broiler feed',
            'date' => $date,
            'source_type' => 'manual',
            'created_by' => $this->user->id,
        ]);

        FlockSale::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'quantity' => 5,
            'unit_price' => 1000,
            'total_amount' => 5000,
            'date' => $date,
            'created_by' => $this->user->id,
        ]);

        FlockExpenditure::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'category' => 'feed',
            'amount' => 1500,
            'currency' => 'NGN',
            'description' => 'Layer feed',
            'date' => $date,
            'source_type' => 'manual',
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/dashboard?preset=30d");

        $response->assertStatus(200)
            ->assertJsonPath('data.kpis.revenue', 5000)
            ->assertJsonPath('data.kpis.cost', 1500)
            ->assertJsonPath('data.kpis.net_profit', 3500);
    }

    public function test_permission_denial_returns_403(): void
    {
        $otherUser = User::factory()->create();
        $token = $otherUser->createToken('test-token')->plainTextToken;
        $this->farm->users()->attach($otherUser->id);

        $role = Role::create([
            'name' => 'viewer',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $viewFarm = Permission::firstOrCreate(['name' => 'view farm', 'guard_name' => 'api']);
        $role->givePermissionTo($viewFarm);
        $otherUser->assignRole($role);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/farms/{$this->farm->id}/dashboard")
            ->assertStatus(403);
    }

    public function test_preset_7d_defaults_period_length(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/dashboard?preset=7d");

        $response->assertStatus(200)
            ->assertJsonPath('data.meta.period_days', 7);
        $this->assertCount(7, $response->json('data.series'));
    }

    public function test_dashboard_succeeds_when_optional_inventory_permissions_missing(): void
    {
        // Drop medication/vaccine inventory permissions — can() must not 500.
        $role = $this->user->roles()->first();
        $role->revokePermissionTo(['view medication inventory', 'view vaccine inventory']);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/dashboard?preset=30d")
            ->assertStatus(200)
            ->assertJsonPath('data.kpis.total_flocks', 1);
    }

    public function test_sold_flocks_still_return_dashboard_payload(): void
    {
        $this->flock->update(['status' => 'sold']);

        Flock::factory()->create([
            'farm_id' => $this->farm->id,
            'house_id' => $this->flock->house_id,
            'poultry_type_id' => $this->poultryType->id,
            'flock_stage_id' => $this->flock->flock_stage_id,
            'quantity' => 200,
            'status' => 'sold',
            'arrival_date' => Carbon::now()->subDays(40)->toDateString(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/dashboard?preset=30d");

        $response->assertStatus(200)
            ->assertJsonPath('data.kpis.active_flocks', 0)
            ->assertJsonPath('data.kpis.total_flocks', 2)
            ->assertJsonPath('data.kpis.active_birds', 0);

        $this->assertCount(2, $response->json('data.flocks'));
        $this->assertNotEmpty($response->json('data.flock_distribution'));
    }
}
