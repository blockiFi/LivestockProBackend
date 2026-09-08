<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerAccountTransaction;
use App\Models\Farm;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalesRecord;
use App\Models\User;
use App\Services\CustomerAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CustomerAccountWalletTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private Country $country;
    private Customer $customer;
    private string $token;

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
            'view customers',
            'create customers',
            'update customers',
            'view customer accounts',
            'top up customer accounts',
            'use customer account for payment',
            'adjust customer accounts',
            'refund customer accounts',
            'reverse customer account transactions',
            'view sales',
            'create sales',
            'update sales',
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

        $this->customer = Customer::create([
            'farm_id' => $this->farm->id,
            'country_id' => $this->country->id,
            'name' => 'ABC Restaurant',
            'is_active' => true,
        ]);
        app(CustomerAccountService::class)->ensureAccount($this->customer);
    }

    public function test_scenario_a_top_up_credits_balance_and_ledger(): void
    {
        $response = $this->withToken($this->token)
            ->postJson("/api/farms/{$this->farm->id}/customers/{$this->customer->id}/account/top-ups", [
                'amount' => 100000,
                'payment_method' => 'bank_transfer',
                'reference' => 'RCP-1',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.new_balance', 100000)
            ->assertJsonPath('data.previous_balance', 0);

        $this->assertDatabaseHas('customer_accounts', [
            'customer_id' => $this->customer->id,
            'balance' => 100000,
        ]);
        $this->assertDatabaseHas('customer_account_transactions', [
            'customer_id' => $this->customer->id,
            'type' => 'top_up',
            'direction' => 'credit',
            'amount' => 100000,
        ]);
    }

    public function test_scenario_b_full_account_payment_on_sale(): void
    {
        $this->topUp(100000);

        $response = $this->withToken($this->token)
            ->postJson("/api/farms/{$this->farm->id}/sales-records", [
                'type' => 'manure',
                'quantity' => 1,
                'unit_price' => 30000,
                'date' => now()->toDateString(),
                'customer_id' => $this->customer->id,
                'payment_mode' => 'customer_account',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.amount_paid', '30000.00');

        $this->assertEquals(70000.0, (float) CustomerAccount::where('customer_id', $this->customer->id)->value('balance'));
        $this->assertDatabaseHas('customer_account_transactions', [
            'customer_id' => $this->customer->id,
            'type' => 'sale_payment',
            'direction' => 'debit',
            'amount' => 30000,
        ]);
    }

    public function test_scenario_c_insufficient_balance_rejects_sale(): void
    {
        $this->topUp(100000);

        $response = $this->withToken($this->token)
            ->postJson("/api/farms/{$this->farm->id}/sales-records", [
                'type' => 'manure',
                'quantity' => 1,
                'unit_price' => 120000,
                'date' => now()->toDateString(),
                'customer_id' => $this->customer->id,
                'payment_mode' => 'customer_account',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.available', 100000)
            ->assertJsonPath('errors.required', 120000)
            ->assertJsonPath('errors.deficit', 20000);

        $this->assertSame(0, SalesRecord::count());
        $this->assertEquals(100000.0, (float) CustomerAccount::where('customer_id', $this->customer->id)->value('balance'));
        $this->assertSame(0, CustomerAccountTransaction::where('type', 'sale_payment')->count());
    }

    public function test_scenario_d_split_account_and_other_payment(): void
    {
        $this->topUp(100000);

        $response = $this->withToken($this->token)
            ->postJson("/api/farms/{$this->farm->id}/sales-records", [
                'type' => 'manure',
                'quantity' => 1,
                'unit_price' => 120000,
                'date' => now()->toDateString(),
                'customer_id' => $this->customer->id,
                'payment_mode' => 'account_and_other',
                'account_amount' => 100000,
                'other_amount' => 20000,
                'other_payment_method' => 'bank_transfer',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.payment_method', 'customer_account+bank_transfer');

        $this->assertEquals(0.0, (float) CustomerAccount::where('customer_id', $this->customer->id)->value('balance'));
        $this->assertDatabaseHas('customer_account_transactions', [
            'type' => 'sale_payment',
            'amount' => 100000,
        ]);
    }

    public function test_scenario_e_refund_credits_without_deleting_original_debit(): void
    {
        $this->topUp(100000);
        $sale = $this->withToken($this->token)
            ->postJson("/api/farms/{$this->farm->id}/sales-records", [
                'type' => 'manure',
                'quantity' => 1,
                'unit_price' => 50000,
                'date' => now()->toDateString(),
                'customer_id' => $this->customer->id,
                'payment_mode' => 'customer_account',
            ])->json('data');

        $response = $this->withToken($this->token)
            ->postJson("/api/farms/{$this->farm->id}/customers/{$this->customer->id}/account/refunds", [
                'sales_record_id' => $sale['id'],
                'amount' => 50000,
            ]);

        $response->assertCreated();
        $this->assertEquals(100000.0, (float) CustomerAccount::where('customer_id', $this->customer->id)->value('balance'));
        $this->assertSame(1, CustomerAccountTransaction::where('type', 'sale_payment')->count());
        $this->assertSame(1, CustomerAccountTransaction::where('type', 'refund')->count());
    }

    public function test_scenario_f_concurrent_debits_only_one_succeeds(): void
    {
        $this->topUp(50000);
        $service = app(CustomerAccountService::class);
        $customer = $this->customer->fresh();

        $saleA = SalesRecord::create([
            'farm_id' => $this->farm->id,
            'type' => 'manure',
            'quantity' => 1,
            'unit_price' => 40000,
            'total_amount' => 40000,
            'amount_paid' => 0,
            'date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'payment_status' => 'pending',
            'created_by' => $this->user->id,
        ]);
        $saleB = SalesRecord::create([
            'farm_id' => $this->farm->id,
            'type' => 'manure',
            'quantity' => 1,
            'unit_price' => 40000,
            'total_amount' => 40000,
            'amount_paid' => 0,
            'date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'payment_status' => 'pending',
            'created_by' => $this->user->id,
        ]);

        $firstOk = false;
        $secondFailed = false;

        DB::transaction(function () use ($service, $customer, $saleA, &$firstOk) {
            $service->debitForSale($customer, $saleA, 40000, [], $this->user);
            $firstOk = true;
        });

        try {
            DB::transaction(function () use ($service, $customer, $saleB) {
                $service->debitForSale($customer, $saleB, 40000, [], $this->user);
            });
        } catch (\App\Exceptions\InsufficientCustomerAccountBalance) {
            $secondFailed = true;
        }

        $this->assertTrue($firstOk);
        $this->assertTrue($secondFailed);
        $this->assertEquals(10000.0, (float) CustomerAccount::where('customer_id', $customer->id)->value('balance'));
        $this->assertSame(1, CustomerAccountTransaction::where('type', 'sale_payment')->count());
    }

    public function test_farm_summary_endpoint(): void
    {
        $this->topUp(25000);

        $this->withToken($this->token)
            ->getJson("/api/farms/{$this->farm->id}/customer-accounts/summary")
            ->assertOk()
            ->assertJsonPath('data.total_balances', 25000)
            ->assertJsonPath('data.customers_with_balance', 1);
    }

    public function test_adjustment_requires_permission_reason(): void
    {
        $this->topUp(1000);

        $this->withToken($this->token)
            ->postJson("/api/farms/{$this->farm->id}/customers/{$this->customer->id}/account/adjustments", [
                'amount' => 100,
                'direction' => 'debit',
            ])
            ->assertStatus(422);

        $this->withToken($this->token)
            ->postJson("/api/farms/{$this->farm->id}/customers/{$this->customer->id}/account/adjustments", [
                'amount' => 100,
                'direction' => 'debit',
                'reason' => 'Correction',
            ])
            ->assertCreated();
    }

    private function topUp(float $amount): void
    {
        $this->withToken($this->token)
            ->postJson("/api/farms/{$this->farm->id}/customers/{$this->customer->id}/account/top-ups", [
                'amount' => $amount,
                'payment_method' => 'cash',
            ])
            ->assertCreated();
    }
}
