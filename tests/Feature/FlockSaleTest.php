<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockDailyRecord;
use App\Models\FlockExpenditure;
use App\Models\FlockSale;
use App\Models\SalesRecord;
use App\Models\FlockStage;
use App\Models\Permission;
use App\Models\PoultryHouse;
use App\Models\PoultryType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlockSaleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private Flock $flock;
    private string $token;

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

        $permissions = collect(['view flocks', 'update flocks'])
            ->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']));

        $ownerRole = Role::create([
            'name' => 'owner',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $ownerRole->givePermissionTo($permissions);

        $this->farm->users()->attach($this->user->id);
        $this->user->assignRole($ownerRole);

        $poultryType = PoultryType::factory()->create();
        $flockStage = FlockStage::factory()->create(['poultry_type_id' => $poultryType->id]);
        $house = PoultryHouse::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_type_id' => $poultryType->id,
        ]);

        $this->flock = Flock::factory()->create([
            'farm_id' => $this->farm->id,
            'house_id' => $house->id,
            'poultry_type_id' => $poultryType->id,
            'flock_stage_id' => $flockStage->id,
            'quantity' => 100,
            'status' => 'active',
        ]);
    }

    public function test_sale_increments_existing_daily_culling_and_reduces_actual_quantity(): void
    {
        $date = now()->toDateString();

        FlockDailyRecord::create([
            'flock_id' => $this->flock->id,
            'farm_id' => $this->farm->id,
            'date' => $date,
            'total_birds' => 100,
            'mortality_count' => 0,
            'culling_count' => 3,
            'mortality' => 0,
            'culls' => 3,
            'recorded_by' => $this->user->id,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flocks/{$this->flock->id}/sales", [
                'quantity' => 10,
                'unit_price' => 2500,
                'date' => $date,
                'customer_name' => 'Buyer A',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.quantity', 10)
            ->assertJsonPath('data.total_amount', '25000.00');

        $dailyRecord = FlockDailyRecord::where('flock_id', $this->flock->id)->whereDate('date', $date)->first();
        $this->assertEquals(13, (int) $dailyRecord->culling_count);
        $this->assertEquals(87, $this->flock->fresh()->actual_quantity);
    }

    public function test_sale_creates_culls_only_daily_record_when_none_exists(): void
    {
        $date = now()->subDay()->toDateString();

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flocks/{$this->flock->id}/sales", [
                'quantity' => 8,
                'unit_price' => 1800,
                'date' => $date,
            ])
            ->assertStatus(201);

        $dailyRecord = FlockDailyRecord::where('flock_id', $this->flock->id)->whereDate('date', $date)->first();
        $this->assertNotNull($dailyRecord);
        $this->assertEquals(8, (int) $dailyRecord->culling_count);
        $this->assertEquals(92, $this->flock->fresh()->actual_quantity);
    }

    public function test_cannot_oversell_beyond_actual_quantity(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flocks/{$this->flock->id}/sales", [
                'quantity' => 120,
                'unit_price' => 1000,
                'date' => now()->toDateString(),
            ])
            ->assertStatus(422);

        $this->assertEquals(0, FlockSale::count());
    }

    public function test_deleting_sale_reverses_culls(): void
    {
        $date = now()->toDateString();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flocks/{$this->flock->id}/sales", [
                'quantity' => 12,
                'unit_price' => 2000,
                'date' => $date,
            ])
            ->assertStatus(201);

        $saleId = $response->json('data.id');
        $this->assertEquals(88, $this->flock->fresh()->actual_quantity);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson("/api/farms/{$this->farm->id}/flocks/{$this->flock->id}/sales/{$saleId}")
            ->assertStatus(200);

        $this->assertEquals(100, $this->flock->fresh()->actual_quantity);
        $this->assertSoftDeleted('flock_sales', ['id' => $saleId]);
    }

    public function test_flock_profit_loss_returns_revenue_minus_cost(): void
    {
        FlockSale::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'quantity' => 20,
            'unit_price' => 3000,
            'total_amount' => 60000,
            'date' => now()->toDateString(),
            'culls_applied' => 0,
            'created_by' => $this->user->id,
        ]);

        FlockExpenditure::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'category' => 'feed',
            'amount' => 15000,
            'date' => now()->toDateString(),
            'source_type' => 'manual',
            'created_by' => $this->user->id,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/flocks/{$this->flock->id}/profit-loss")
            ->assertStatus(200)
            ->assertJsonPath('data.total_revenue', 60000)
            ->assertJsonPath('data.total_cost', 15000)
            ->assertJsonPath('data.net_profit', 45000);
    }

    public function test_farm_sales_statistics_returns_totals_and_flock_rows(): void
    {
        FlockSale::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'quantity' => 15,
            'unit_price' => 2000,
            'total_amount' => 30000,
            'date' => now()->toDateString(),
            'culls_applied' => 0,
            'created_by' => $this->user->id,
        ]);

        FlockExpenditure::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'category' => 'other',
            'amount' => 5000,
            'date' => now()->toDateString(),
            'source_type' => 'manual',
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/sales-statistics")
            ->assertStatus(200);

        $this->assertEquals(30000, (float) $response->json('data.total_revenue'));
        $this->assertEquals(5000, (float) $response->json('data.total_cost'));
        $this->assertEquals(25000, (float) $response->json('data.net_profit'));
        $this->assertCount(1, $response->json('data.flocks'));
    }

    public function test_farm_sales_statistics_includes_product_revenue_breakdown(): void
    {
        $date = now()->toDateString();

        FlockSale::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'quantity' => 10,
            'unit_price' => 2000,
            'total_amount' => 20000,
            'date' => $date,
            'culls_applied' => 0,
            'created_by' => $this->user->id,
        ]);

        SalesRecord::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'type' => 'egg',
            'quantity' => 50,
            'unit_price' => 40,
            'total_amount' => 2000,
            'date' => $date,
            'payment_status' => 'paid',
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/sales-statistics?date_from={$date}&date_to={$date}")
            ->assertStatus(200);

        $this->assertEquals(22000, (float) $response->json('data.total_revenue'));
        $this->assertEquals(20000, (float) $response->json('data.revenue_by_type.live_bird'));
        $this->assertEquals(2000, (float) $response->json('data.revenue_by_type.egg'));

        $flockRow = collect($response->json('data.flocks'))->first();
        $this->assertEquals(20000, (float) $flockRow['live_bird_revenue']);
        $this->assertEquals(2000, (float) $flockRow['product_revenue']);
        $this->assertEquals(22000, (float) $flockRow['total_revenue']);
    }

    public function test_farm_sales_statistics_excludes_active_broiler_batches(): void
    {
        $date = now()->toDateString();

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
            'culls_applied' => 0,
            'created_by' => $this->user->id,
        ]);

        FlockExpenditure::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $activeBroiler->id,
            'category' => 'feed',
            'amount' => 20000,
            'date' => $date,
            'source_type' => 'manual',
            'created_by' => $this->user->id,
        ]);

        FlockSale::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'quantity' => 15,
            'unit_price' => 2000,
            'total_amount' => 30000,
            'date' => $date,
            'culls_applied' => 0,
            'created_by' => $this->user->id,
        ]);

        FlockExpenditure::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'category' => 'other',
            'amount' => 5000,
            'date' => $date,
            'source_type' => 'manual',
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/sales-statistics?date_from={$date}&date_to={$date}")
            ->assertStatus(200);

        $this->assertEquals(30000, (float) $response->json('data.total_revenue'));
        $this->assertEquals(5000, (float) $response->json('data.total_cost'));
        $this->assertEquals(25000, (float) $response->json('data.net_profit'));
        $this->assertCount(1, $response->json('data.flocks'));
        $this->assertEquals($this->flock->id, $response->json('data.flocks.0.flock_id'));
    }

    public function test_farm_sales_statistics_includes_completed_broiler_batches(): void
    {
        $date = now()->toDateString();

        $broilerType = PoultryType::factory()->create(['name' => 'Broiler']);
        $broilerStage = FlockStage::factory()->create(['poultry_type_id' => $broilerType->id]);
        $broilerHouse = PoultryHouse::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_type_id' => $broilerType->id,
        ]);

        $soldBroiler = Flock::factory()->create([
            'farm_id' => $this->farm->id,
            'house_id' => $broilerHouse->id,
            'poultry_type_id' => $broilerType->id,
            'flock_stage_id' => $broilerStage->id,
            'quantity' => 200,
            'status' => 'sold',
            'name' => 'Completed Broiler Batch',
        ]);

        FlockSale::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $soldBroiler->id,
            'quantity' => 200,
            'unit_price' => 2500,
            'total_amount' => 500000,
            'date' => $date,
            'culls_applied' => 0,
            'created_by' => $this->user->id,
        ]);

        FlockExpenditure::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $soldBroiler->id,
            'category' => 'feed',
            'amount' => 300000,
            'date' => $date,
            'source_type' => 'manual',
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/sales-statistics?date_from={$date}&date_to={$date}")
            ->assertStatus(200);

        $this->assertEquals(500000, (float) $response->json('data.total_revenue'));
        $this->assertEquals(300000, (float) $response->json('data.total_cost'));
        $this->assertEquals(200000, (float) $response->json('data.net_profit'));
        $this->assertCount(1, $response->json('data.flocks'));
        $this->assertEquals($soldBroiler->id, $response->json('data.flocks.0.flock_id'));
    }

    public function test_selling_all_birds_ends_batch_as_sold(): void
    {
        $date = now()->toDateString();
        $house = PoultryHouse::find($this->flock->house_id);
        $house->update(['status' => 'active']);

        \App\Models\FlockHouseAllocation::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'house_id' => $house->id,
            'quantity' => 100,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flocks/{$this->flock->id}/sales", [
                'quantity' => 100,
                'unit_price' => 2500,
                'date' => $date,
            ])
            ->assertStatus(201)
            ->assertJsonPath('message', 'Flock sale recorded successfully. Batch ended — all birds have been sold.');

        $flock = $this->flock->fresh();
        $this->assertEquals(0, $flock->actual_quantity);
        $this->assertEquals('sold', $flock->status);
        $this->assertEquals($date, $flock->actual_end_date->toDateString());

        $this->assertDatabaseHas('poultry_houses', [
            'id' => $house->id,
            'status' => 'empty',
        ]);
    }

    public function test_deleting_final_sale_reopens_batch_when_birds_remain(): void
    {
        $date = now()->toDateString();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flocks/{$this->flock->id}/sales", [
                'quantity' => 100,
                'unit_price' => 2500,
                'date' => $date,
            ])
            ->assertStatus(201);

        $saleId = $response->json('data.id');
        $this->assertEquals('sold', $this->flock->fresh()->status);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson("/api/farms/{$this->farm->id}/flocks/{$this->flock->id}/sales/{$saleId}")
            ->assertStatus(200);

        $flock = $this->flock->fresh();
        $this->assertEquals('active', $flock->status);
        $this->assertNull($flock->actual_end_date);
        $this->assertEquals(100, $flock->actual_quantity);
    }
}
