<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerAccountTransaction;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockDailyRecord;
use App\Models\FlockStage;
use App\Models\Permission;
use App\Models\PoultryHouse;
use App\Models\PoultryType;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesRecord;
use App\Models\User;
use App\Services\CustomerAccountService;
use App\Services\SalesProfitLossService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SalesOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private Country $country;
    private Customer $customer;
    private string $token;
    /** @var Flock[] */
    private array $flocks = [];
    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;
        $this->country = Country::factory()->create();
        $this->farm = Farm::factory()->create([
            'created_by' => $this->user->id,
            'country_id' => $this->country->id,
        ]);

        $permissions = collect([
            'view sales', 'create sales', 'update sales', 'delete sales', 'view flocks',
            'view customers', 'view customer accounts', 'use customer account for payment',
        ])->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']));

        $role = Role::create(['name' => 'owner', 'guard_name' => 'api', 'farm_id' => $this->farm->id]);
        $role->givePermissionTo($permissions);
        $this->farm->users()->attach($this->user->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->farm->id);
        $this->user->assignRole($role);

        $poultryType = PoultryType::factory()->create();
        $stage = FlockStage::factory()->create(['poultry_type_id' => $poultryType->id]);
        $house = PoultryHouse::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_type_id' => $poultryType->id,
        ]);

        $this->date = now()->toDateString();
        // Three batches with 300, 600 and 900 eggs (10, 20 and 30 crates).
        foreach ([300, 600, 900] as $i => $eggs) {
            $flock = Flock::factory()->create([
                'farm_id' => $this->farm->id,
                'house_id' => $house->id,
                'poultry_type_id' => $poultryType->id,
                'flock_stage_id' => $stage->id,
                'quantity' => 100,
                'status' => 'active',
                'name' => 'Batch '.($i + 1),
            ]);
            FlockDailyRecord::create([
                'flock_id' => $flock->id,
                'farm_id' => $this->farm->id,
                'date' => $this->date,
                'total_birds' => 100,
                'mortality_count' => 0,
                'culling_count' => 0,
                'eggs_collected' => $eggs,
                'recorded_by' => $this->user->id,
            ]);
            $this->flocks[] = $flock;
        }

        $this->customer = Customer::create([
            'farm_id' => $this->farm->id,
            'country_id' => $this->country->id,
            'name' => 'ABC Restaurant',
            'is_active' => true,
        ]);
    }

    private function api()
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token);
    }

    private function eggOrder(array $lines, array $extra = []): array
    {
        return array_merge([
            'type' => 'egg',
            'date' => $this->date,
            'customer_id' => $this->customer->id,
            'payment_mode' => 'cash',
            'lines' => $lines,
        ], $extra);
    }

    public function test_creates_one_order_with_a_line_per_batch(): void
    {
        $response = $this->api()->postJson("/api/farms/{$this->farm->id}/sales-orders", $this->eggOrder([
            ['flock_id' => $this->flocks[0]->id, 'quantity' => 5, 'unit_price' => 3000],
            ['flock_id' => $this->flocks[1]->id, 'quantity' => 10, 'unit_price' => 3200],
        ]));

        $response->assertCreated()
            ->assertJsonCount(2, 'data.lines')
            ->assertJsonPath('data.payment_status', 'paid');
        $this->assertEquals(47000.0, (float) $response->json('data.total_amount'));

        $orderId = $response->json('data.id');
        $this->assertSame(2, SalesRecord::where('sales_order_id', $orderId)->count());
        $this->assertDatabaseHas('sales_records', [
            'sales_order_id' => $orderId, 'flock_id' => $this->flocks[1]->id, 'total_amount' => 32000, 'amount_paid' => 32000,
        ]);

        // Per-batch P&L still attributes each line to its own batch.
        $pnl = app(SalesProfitLossService::class);
        $this->assertEquals(15000.0, $pnl->flockSummary($this->farm->id, $this->flocks[0]->id)['product_revenue']);
        $this->assertEquals(32000.0, $pnl->flockSummary($this->farm->id, $this->flocks[1]->id)['product_revenue']);
    }

    public function test_overselling_one_batch_rejects_the_whole_order(): void
    {
        $response = $this->api()->postJson("/api/farms/{$this->farm->id}/sales-orders", $this->eggOrder([
            ['flock_id' => $this->flocks[0]->id, 'quantity' => 11, 'unit_price' => 3000],
            ['flock_id' => $this->flocks[1]->id, 'quantity' => 5, 'unit_price' => 3000],
        ]));

        $response->assertStatus(422);
        $this->assertSame($this->flocks[0]->id, $response->json('errors.shortages.0.flock_id'));
        $this->assertSame(0, SalesOrder::count());
        $this->assertSame(0, SalesRecord::count());
    }

    public function test_lines_for_the_same_batch_are_summed_for_stock(): void
    {
        $this->api()->postJson("/api/farms/{$this->farm->id}/sales-orders", $this->eggOrder([
            ['flock_id' => $this->flocks[0]->id, 'quantity' => 6, 'unit_price' => 3000],
            ['flock_id' => $this->flocks[0]->id, 'quantity' => 6, 'unit_price' => 2800],
        ]))->assertStatus(422);
    }

    public function test_selling_all_stock_across_batches(): void
    {
        $stock = $this->api()->getJson("/api/farms/{$this->farm->id}/sales-orders/egg-stock?date={$this->date}")
            ->assertOk()
            ->json('data');

        $lines = collect($stock)->filter(fn ($row) => $row['available'] > 0)
            ->map(fn ($row) => ['flock_id' => $row['flock_id'], 'quantity' => $row['available_crates'], 'unit_price' => 3000])
            ->values()->all();
        $this->assertCount(3, $lines);

        $this->api()->postJson("/api/farms/{$this->farm->id}/sales-orders", $this->eggOrder($lines))->assertCreated();

        $after = $this->api()->getJson("/api/farms/{$this->farm->id}/sales-orders/egg-stock?date={$this->date}")->json('data');
        foreach ($after as $row) {
            $this->assertEquals(0.0, (float) $row['available']);
        }
    }

    public function test_partial_payment_is_split_pro_rata_to_the_cent(): void
    {
        $response = $this->api()->postJson("/api/farms/{$this->farm->id}/sales-orders", $this->eggOrder([
            ['flock_id' => $this->flocks[0]->id, 'quantity' => 1, 'unit_price' => 1000],
            ['flock_id' => $this->flocks[1]->id, 'quantity' => 1, 'unit_price' => 1000],
            ['flock_id' => $this->flocks[2]->id, 'quantity' => 1, 'unit_price' => 1000],
        ], ['payment_mode' => 'partial', 'amount_paid' => 1000, 'payment_method' => 'cash']));

        $response->assertCreated()->assertJsonPath('data.payment_status', 'partial');
        $paid = SalesRecord::where('sales_order_id', $response->json('data.id'))->pluck('amount_paid')->map(fn ($v) => (float) $v);
        $this->assertEquals(1000.0, round($paid->sum(), 2));
        $this->assertEqualsCanonicalizing([333.34, 333.33, 333.33], $paid->all());
    }

    public function test_account_payment_debits_once_per_line_and_refunds_on_delete(): void
    {
        $accounts = app(CustomerAccountService::class);
        $accounts->ensureAccount($this->customer);
        $accounts->topUp($this->customer, ['amount' => 100000, 'payment_method' => 'cash'], $this->user);

        $response = $this->api()->postJson("/api/farms/{$this->farm->id}/sales-orders", $this->eggOrder([
            ['flock_id' => $this->flocks[0]->id, 'quantity' => 5, 'unit_price' => 3000],
            ['flock_id' => $this->flocks[1]->id, 'quantity' => 5, 'unit_price' => 3000],
        ], ['payment_mode' => 'customer_account']));

        $response->assertCreated()->assertJsonPath('data.account_debited', 30000);
        $this->assertEquals(70000.0, (float) CustomerAccount::where('customer_id', $this->customer->id)->value('balance'));
        $this->assertSame(2, CustomerAccountTransaction::where('type', CustomerAccountTransaction::TYPE_SALE_PAYMENT)->count());

        $this->api()->deleteJson("/api/farms/{$this->farm->id}/sales-orders/{$response->json('data.id')}")->assertOk();
        $this->assertEquals(100000.0, (float) CustomerAccount::where('customer_id', $this->customer->id)->value('balance'));
        $this->assertSame(0, SalesRecord::count());
        $this->assertSoftDeleted('sales_orders', ['id' => $response->json('data.id')]);
    }

    public function test_insufficient_account_balance_blocks_the_whole_order(): void
    {
        $accounts = app(CustomerAccountService::class);
        $accounts->ensureAccount($this->customer);
        $accounts->topUp($this->customer, ['amount' => 10000, 'payment_method' => 'cash'], $this->user);

        $this->api()->postJson("/api/farms/{$this->farm->id}/sales-orders", $this->eggOrder([
            ['flock_id' => $this->flocks[0]->id, 'quantity' => 5, 'unit_price' => 3000],
            ['flock_id' => $this->flocks[1]->id, 'quantity' => 5, 'unit_price' => 3000],
        ], ['payment_mode' => 'customer_account']))->assertStatus(422);

        $this->assertSame(0, SalesRecord::count());
        $this->assertEquals(10000.0, (float) CustomerAccount::where('customer_id', $this->customer->id)->value('balance'));
    }

    public function test_update_replaces_lines_and_rechecks_stock_without_counting_itself(): void
    {
        $orderId = $this->api()->postJson("/api/farms/{$this->farm->id}/sales-orders", $this->eggOrder([
            ['flock_id' => $this->flocks[0]->id, 'quantity' => 10, 'unit_price' => 3000],
        ]))->assertCreated()->json('data.id');

        // Same 10 crates plus a new batch: must not count the order's own 10 crates as already sold.
        $response = $this->api()->putJson("/api/farms/{$this->farm->id}/sales-orders/{$orderId}", $this->eggOrder([
            ['flock_id' => $this->flocks[0]->id, 'quantity' => 10, 'unit_price' => 3000],
            ['flock_id' => $this->flocks[2]->id, 'quantity' => 30, 'unit_price' => 3000],
        ]));

        $response->assertOk()->assertJsonCount(2, 'data.lines');
        $this->assertEquals(120000.0, (float) $response->json('data.total_amount'));
        $this->assertSame(2, SalesRecord::where('sales_order_id', $orderId)->count());
    }

    public function test_egg_lines_need_a_batch_and_manure_can_be_farm_level(): void
    {
        $this->api()->postJson("/api/farms/{$this->farm->id}/sales-orders", $this->eggOrder([
            ['flock_id' => null, 'quantity' => 1, 'unit_price' => 3000],
        ]))->assertStatus(422);

        $this->api()->postJson("/api/farms/{$this->farm->id}/sales-orders", [
            'type' => 'manure',
            'date' => $this->date,
            'payment_mode' => 'cash',
            'lines' => [
                ['flock_id' => null, 'quantity' => 3, 'unit_price' => 500],
                ['flock_id' => $this->flocks[0]->id, 'quantity' => 2, 'unit_price' => 500],
            ],
        ])->assertCreated()->assertJsonCount(2, 'data.lines');
    }

    public function test_order_lines_cannot_be_edited_one_by_one(): void
    {
        $order = $this->api()->postJson("/api/farms/{$this->farm->id}/sales-orders", $this->eggOrder([
            ['flock_id' => $this->flocks[0]->id, 'quantity' => 2, 'unit_price' => 3000],
            ['flock_id' => $this->flocks[1]->id, 'quantity' => 2, 'unit_price' => 3000],
        ]))->json('data');

        $lineId = $order['lines'][0]['id'];
        $this->api()->deleteJson("/api/farms/{$this->farm->id}/sales-records/{$lineId}")->assertStatus(409);
        $this->api()->putJson("/api/farms/{$this->farm->id}/sales-records/{$lineId}", ['quantity' => 1])->assertStatus(409);
    }
}
