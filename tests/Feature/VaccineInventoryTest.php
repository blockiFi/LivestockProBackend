<?php

namespace Tests\Feature;

use App\Models\AdministrationMethod;
use App\Models\Country;
use App\Models\Farm;
use App\Models\Permission;
use App\Models\PoultryVaccine;
use App\Models\PoultryVaccineInventory;
use App\Models\PoultryVaccineProduct;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class VaccineInventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private string $token;
    private AdministrationMethod $method;
    private PoultryVaccine $newcastle;

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
            'view vaccines',
            'view vaccine inventory',
            'manage vaccine inventory',
        ])->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']));

        $ownerRole = Role::create([
            'name' => 'owner',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $ownerRole->givePermissionTo($permissions);

        $this->farm->users()->attach($this->user->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->farm->id);
        $this->user->roles()->attach($ownerRole->id, [
            'model_type' => User::class,
            'farm_id' => $this->farm->id,
        ]);
        $this->user->unsetRelation('roles');
        $this->user->unsetRelation('permissions');

        $this->method = AdministrationMethod::create(['name' => 'Eye drop']);
        $this->newcastle = PoultryVaccine::create([
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'name' => 'Newcastle Disease',
            'description' => 'ND vaccines',
        ]);
    }

    private function product(array $overrides = []): PoultryVaccineProduct
    {
        return PoultryVaccineProduct::create(array_merge([
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_vaccine_id' => $this->newcastle->id,
            'name' => 'Lasota',
            'manufacturer' => 'VetVax',
            'administration_method_id' => $this->method->id,
        ], $overrides));
    }

    private function postInventory(array $payload)
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/vaccine-inventory", $payload);
    }

    public function test_inventory_can_be_added_for_a_product_with_no_stock(): void
    {
        $product = $this->product();

        $response = $this->postInventory([
            'poultry_vaccine_product_id' => $product->id,
            'quantity' => 10,
            'unit_cost' => 2500,
            'batch_number' => 'LAS-1',
            'expiry_date' => now()->addYear()->toDateString(),
            'notes' => 'Keep refrigerated',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.product.id', $product->id);

        $inventory = PoultryVaccineInventory::findOrFail($response->json('data.id'));
        $this->assertEqualsWithDelta(10, (float) $inventory->quantity, 0.001);
        $this->assertEqualsWithDelta(10, (float) $inventory->available_quantity, 0.001);
        $this->assertSame('VetVax', $inventory->manufacturer);
        $this->assertSame('Keep refrigerated', $inventory->notes);
        $this->assertNotNull($inventory->last_restocked);
    }

    public function test_batch_expiring_today_is_accepted(): void
    {
        $this->postInventory([
            'poultry_vaccine_product_id' => $this->product()->id,
            'quantity' => 5,
            'unit_cost' => 100,
            'expiry_date' => now()->toDateString(),
        ])->assertStatus(201);
    }

    public function test_expired_batch_is_rejected(): void
    {
        $this->postInventory([
            'poultry_vaccine_product_id' => $this->product()->id,
            'quantity' => 5,
            'unit_cost' => 100,
            'expiry_date' => now()->subDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('expiry_date');
    }

    public function test_inventory_cannot_be_added_for_another_farms_product(): void
    {
        $otherFarm = Farm::factory()->create();
        $foreign = $this->product(['farm_id' => $otherFarm->id, 'name' => 'Foreign Vax']);

        $this->postInventory([
            'poultry_vaccine_product_id' => $foreign->id,
            'quantity' => 5,
            'unit_cost' => 100,
        ])->assertStatus(403);
    }

    public function test_vaccine_data_returns_products_with_farm_scoped_inventories(): void
    {
        $product = $this->product();
        $mine = PoultryVaccineInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_vaccine_product_id' => $product->id,
            'quantity' => 8,
            'unit_cost' => 10,
            'status' => 'available',
        ]);
        PoultryVaccineInventory::create([
            'farm_id' => Farm::factory()->create()->id,
            'poultry_vaccine_product_id' => $product->id,
            'quantity' => 99,
            'unit_cost' => 10,
            'status' => 'available',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/vaccines/data")
            ->assertOk();

        $vaccine = collect($response->json('data'))->firstWhere('id', $this->newcastle->id);
        $this->assertNotNull($vaccine);
        $this->assertCount(1, $vaccine['products']);
        $this->assertCount(1, $vaccine['products'][0]['inventories']);
        $this->assertSame($mine->id, $vaccine['products'][0]['inventories'][0]['id']);
        $this->assertEqualsWithDelta(8, (float) $vaccine['products'][0]['inventories'][0]['available_quantity'], 0.001);
    }

    public function test_using_stock_keeps_available_quantity_in_sync(): void
    {
        $inventory = PoultryVaccineInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_vaccine_product_id' => $this->product()->id,
            'quantity' => 20,
            'unit_cost' => 10,
            'status' => 'available',
        ]);

        $inventory->quantity -= 6;
        $inventory->save();

        $this->assertEqualsWithDelta(14, (float) $inventory->fresh()->available_quantity, 0.001);
    }
}
