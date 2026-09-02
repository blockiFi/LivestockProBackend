<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Customer;
use App\Models\Farm;
use App\Models\FarmSetting;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalesRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private Country $country;
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
            'delete customers',
            'view sales',
            'create sales',
            'view invoices',
            'create invoices',
            'update invoices',
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

        FarmSetting::create([
            'farm_id' => $this->farm->id,
            'invoice_prefix' => 'INV',
            'invoice_next_number' => 1,
            'invoice_tax_enabled' => true,
            'invoice_tax_rate' => 10,
        ]);
    }

    public function test_can_create_and_show_customer(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/farms/{$this->farm->id}/customers", [
                'name' => 'Acme Buyer',
                'email' => 'buyer@example.com',
                'phone' => '08012345678',
                'country_id' => $this->country->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Acme Buyer');

        $customerId = $response->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson("/api/farms/{$this->farm->id}/customers/{$customerId}")
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Acme Buyer')
            ->assertJsonPath('data.summary.product_sale_count', 0);
    }

    public function test_product_sale_denormalizes_customer_fields(): void
    {
        $customer = Customer::create([
            'farm_id' => $this->farm->id,
            'name' => 'Linked Customer',
            'phone' => '08099998888',
            'country_id' => $this->country->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/farms/{$this->farm->id}/sales-records", [
                'type' => 'manure',
                'quantity' => 10,
                'unit_price' => 100,
                'date' => now()->toDateString(),
                'customer_id' => $customer->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.customer_name', 'Linked Customer')
            ->assertJsonPath('data.customer_phone', '08099998888');
    }

    public function test_can_create_invoice_with_auto_number_and_tax(): void
    {
        $customer = Customer::create([
            'farm_id' => $this->farm->id,
            'name' => 'Invoice Customer',
            'country_id' => $this->country->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/farms/{$this->farm->id}/invoices", [
                'customer_id' => $customer->id,
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(14)->toDateString(),
                'items' => [
                    ['description' => 'Eggs', 'quantity' => 2, 'unit_price' => 1000],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.invoice_number', 'INV-0001')
            ->assertJsonPath('data.subtotal', '2000.00')
            ->assertJsonPath('data.tax_amount', '200.00')
            ->assertJsonPath('data.total', '2200.00');

        $this->assertDatabaseHas('invoices', [
            'farm_id' => $this->farm->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-0001',
        ]);
    }

    public function test_customer_history_includes_product_sales(): void
    {
        $customer = Customer::create([
            'farm_id' => $this->farm->id,
            'name' => 'History Customer',
            'country_id' => $this->country->id,
        ]);

        SalesRecord::create([
            'farm_id' => $this->farm->id,
            'type' => 'manure',
            'quantity' => 5,
            'unit_price' => 50,
            'total_amount' => 250,
            'date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson("/api/farms/{$this->farm->id}/customers/{$customer->id}/history?type=product");

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data.data')));
    }
}
