<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Customer;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockDailyRecord;
use App\Models\FlockExpenditure;
use App\Models\FlockSale;
use App\Models\FlockStage;
use App\Models\Permission;
use App\Models\PoultryHouse;
use App\Models\PoultryType;
use App\Models\Role;
use App\Models\SalesRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SalesRecordTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private Flock $flock;
    private Customer $customer;
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

        $permissions = collect([
            'view sales',
            'create sales',
            'update sales',
            'delete sales',
            'view flocks',
        ])->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']));

        $ownerRole = Role::create([
            'name' => 'owner',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $ownerRole->givePermissionTo($permissions);

        $this->farm->users()->attach($this->user->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->farm->id);
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

        $this->customer = Customer::create([
            'farm_id' => $this->farm->id,
            'name' => 'Test Customer',
            'email' => 'customer@example.com',
            'phone' => '08000000000',
            'country_id' => $country->id,
        ]);
    }

    public function test_can_create_egg_sale(): void
    {
        $date = now()->toDateString();

        FlockDailyRecord::create([
            'flock_id' => $this->flock->id,
            'farm_id' => $this->farm->id,
            'date' => $date,
            'total_birds' => 100,
            'mortality_count' => 0,
            'culling_count' => 0,
            'eggs_collected' => 200,
            'recorded_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/sales-records", [
                'type' => 'egg',
                'flock_id' => $this->flock->id,
                'quantity' => 4, // crates
                'unit_price' => 1500, // per crate
                'date' => $date,
                'customer_id' => $this->customer->id,
                'payment_status' => 'paid',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'egg');
        $this->assertEquals(6000.0, (float) $response->json('data.total_amount'));

        $this->assertDatabaseHas('sales_records', [
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'type' => 'egg',
            'quantity' => 4,
        ]);
    }

    public function test_egg_sale_rejects_quantity_above_production(): void
    {
        $date = now()->toDateString();

        FlockDailyRecord::create([
            'flock_id' => $this->flock->id,
            'farm_id' => $this->farm->id,
            'date' => $date,
            'total_birds' => 100,
            'mortality_count' => 0,
            'culling_count' => 0,
            'eggs_collected' => 50,
            'recorded_by' => $this->user->id,
        ]);

        // 3 crates = 90 eggs > 50 available
        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/sales-records", [
                'type' => 'egg',
                'flock_id' => $this->flock->id,
                'quantity' => 3,
                'unit_price' => 1500,
                'date' => $date,
            ])
            ->assertStatus(422);
    }

    public function test_egg_sale_can_use_cumulative_stock_from_prior_days(): void
    {
        FlockDailyRecord::create([
            'flock_id' => $this->flock->id,
            'farm_id' => $this->farm->id,
            'date' => now()->subDays(2)->toDateString(),
            'total_birds' => 100,
            'mortality_count' => 0,
            'culling_count' => 0,
            'eggs_collected' => 200,
            'eggs_broken' => 10,
            'recorded_by' => $this->user->id,
        ]);
        FlockDailyRecord::create([
            'flock_id' => $this->flock->id,
            'farm_id' => $this->farm->id,
            'date' => now()->subDay()->toDateString(),
            'total_birds' => 100,
            'mortality_count' => 0,
            'culling_count' => 0,
            'eggs_collected' => 180,
            'eggs_broken' => 5,
            'recorded_by' => $this->user->id,
        ]);

        // Sale day itself has little production, but prior stock (365 eggs) is enough for 10 crates (300 eggs).
        FlockDailyRecord::create([
            'flock_id' => $this->flock->id,
            'farm_id' => $this->farm->id,
            'date' => now()->toDateString(),
            'total_birds' => 100,
            'mortality_count' => 0,
            'culling_count' => 0,
            'eggs_collected' => 20,
            'recorded_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/sales-records", [
                'type' => 'egg',
                'flock_id' => $this->flock->id,
                'quantity' => 10, // crates
                'unit_price' => 1500,
                'date' => now()->toDateString(),
                'customer_id' => $this->customer->id,
                'payment_status' => 'paid',
            ]);

        $response->assertStatus(201);
        $this->assertEquals(10.0, (float) $response->json('data.quantity'));
    }

    public function test_egg_stock_endpoint_returns_breakdown(): void
    {
        FlockDailyRecord::create([
            'flock_id' => $this->flock->id,
            'farm_id' => $this->farm->id,
            'date' => now()->toDateString(),
            'total_birds' => 100,
            'mortality_count' => 0,
            'culling_count' => 0,
            'eggs_collected' => 200,
            'eggs_broken' => 10,
            'recorded_by' => $this->user->id,
        ]);

        // 2 crates sold = 60 eggs
        SalesRecord::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'type' => 'egg',
            'quantity' => 2,
            'unit_price' => 1200,
            'total_amount' => 2400,
            'amount_paid' => 2400,
            'date' => now()->toDateString(),
            'payment_status' => 'paid',
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/sales-records/egg-stock?flock_id={$this->flock->id}&date=" . now()->toDateString());

        $response->assertStatus(200)
            ->assertJsonPath('data.produced', 200)
            ->assertJsonPath('data.broken', 10)
            ->assertJsonPath('data.sold', 60)
            ->assertJsonPath('data.available', 130);
    }

    public function test_egg_sale_stock_uses_sale_date_not_future_collections(): void
    {
        FlockDailyRecord::create([
            'flock_id' => $this->flock->id,
            'farm_id' => $this->farm->id,
            'date' => now()->subDays(5)->toDateString(),
            'total_birds' => 100,
            'mortality_count' => 0,
            'culling_count' => 0,
            'eggs_collected' => 100,
            'recorded_by' => $this->user->id,
        ]);
        FlockDailyRecord::create([
            'flock_id' => $this->flock->id,
            'farm_id' => $this->farm->id,
            'date' => now()->toDateString(),
            'total_birds' => 100,
            'mortality_count' => 0,
            'culling_count' => 0,
            'eggs_collected' => 500,
            'recorded_by' => $this->user->id,
        ]);

        // Backdated sale: 5 crates = 150 eggs > 100 available on that date.
        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/sales-records", [
                'type' => 'egg',
                'flock_id' => $this->flock->id,
                'quantity' => 5,
                'unit_price' => 1500,
                'date' => now()->subDays(5)->toDateString(),
                'customer_id' => $this->customer->id,
                'payment_status' => 'paid',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.available', 100);
    }

    public function test_can_create_manure_sale_without_flock(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/sales-records", [
                'type' => 'manure',
                'quantity' => 10,
                'unit_price' => 500,
                'date' => now()->toDateString(),
                'customer_name' => 'Walk-in',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'manure')
            ->assertJsonPath('data.flock_id', null);
    }

    public function test_pnl_includes_live_bird_and_product_revenue(): void
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
            'quantity' => 2, // crates
            'unit_price' => 1500, // per crate
            'total_amount' => 3000,
            'date' => $date,
            'payment_status' => 'paid',
            'created_by' => $this->user->id,
        ]);

        FlockExpenditure::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'category' => 'feed',
            'amount' => 5000,
            'date' => $date,
            'source_type' => 'manual',
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/sales-statistics?date_from={$date}&date_to={$date}");

        $response->assertStatus(200)
            ->assertJsonPath('data.total_revenue', 23000)
            ->assertJsonPath('data.revenue_by_type.live_bird', 20000)
            ->assertJsonPath('data.revenue_by_type.egg', 3000)
            ->assertJsonPath('data.total_cost', 5000)
            ->assertJsonPath('data.net_profit', 18000);
    }

    public function test_can_list_and_delete_sales_records(): void
    {
        $record = SalesRecord::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'type' => 'meat',
            'quantity' => 5,
            'unit_price' => 1000,
            'total_amount' => 5000,
            'date' => now()->toDateString(),
            'payment_status' => 'paid',
            'created_by' => $this->user->id,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/sales-records?type=meat")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson("/api/farms/{$this->farm->id}/sales-records/{$record->id}")
            ->assertStatus(200);

        $this->assertSoftDeleted('sales_records', ['id' => $record->id]);
    }
}
