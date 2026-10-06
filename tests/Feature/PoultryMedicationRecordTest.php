<?php

namespace Tests\Feature;

use App\Models\AdministrationMethod;
use App\Models\Country;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockStage;
use App\Models\MedicationProduct;
use App\Models\Permission;
use App\Models\PoultryHouse;
use App\Models\PoultryMedication;
use App\Models\PoultryMedicationInventory;
use App\Models\PoultryMedicationRecord;
use App\Models\PoultryType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PoultryMedicationRecordTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private string $token;
    private Flock $flock;
    private AdministrationMethod $method;
    private PoultryMedication $antibiotics;
    private PoultryMedication $vitamins;
    private MedicationProduct $product;
    private PoultryMedicationInventory $inventory;

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
            'view medications',
            'view medication records',
            'create medication records',
            'delete medication records',
            'delete medication products',
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

        $this->method = AdministrationMethod::create(['name' => 'Drinking water']);

        $this->antibiotics = PoultryMedication::create([
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'name' => 'Antibiotics',
        ]);
        $this->vitamins = PoultryMedication::create([
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'name' => 'Vitamins',
        ]);

        $this->product = MedicationProduct::create([
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_medication_id' => $this->antibiotics->id,
            'name' => 'Oxytetracycline',
            'manufacturer' => 'Acme',
            'administration_method_id' => $this->method->id,
        ]);

        $this->inventory = PoultryMedicationInventory::create([
            'farm_id' => $this->farm->id,
            'medication_product_id' => $this->product->id,
            'quantity' => 50,
            'available_quantity' => 50,
            'unit_cost' => 10,
            'status' => 'available',
            'batch_number' => 'OXY-1',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'poultry_medication_id' => $this->antibiotics->id,
            'poultry_medication_inventory_id' => $this->inventory->id,
            'date' => now()->toDateString(),
            'administered_by' => 'Farm Manager',
            'dosage' => 5,
            'dosage_unit' => 'g',
            'administration_method_id' => $this->method->id,
        ], $overrides);
    }

    private function postRecord(array $overrides = [])
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/medication-records", $this->payload($overrides));
    }

    public function test_valid_record_deducts_stock_and_returns_product(): void
    {
        $response = $this->postRecord();

        $response->assertOk()
            ->assertJsonPath('data.medication_inventory.product.id', $this->product->id)
            ->assertJsonPath('data.medication_inventory.product.name', 'Oxytetracycline');

        $this->assertEqualsWithDelta(45, (float) $this->inventory->fresh()->quantity, 0.001);
    }

    public function test_batch_from_another_farm_is_rejected(): void
    {
        $otherFarm = Farm::factory()->create();
        $foreignBatch = PoultryMedicationInventory::create([
            'farm_id' => $otherFarm->id,
            'medication_product_id' => $this->product->id,
            'quantity' => 50,
            'unit_cost' => 10,
            'status' => 'available',
        ]);

        $this->postRecord(['poultry_medication_inventory_id' => $foreignBatch->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('poultry_medication_inventory_id');

        $this->assertEqualsWithDelta(50, (float) $foreignBatch->fresh()->quantity, 0.001);
        $this->assertSame(0, PoultryMedicationRecord::count());
    }

    public function test_batch_for_a_different_medication_type_is_rejected(): void
    {
        $this->postRecord(['poultry_medication_id' => $this->vitamins->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('poultry_medication_inventory_id');

        $this->assertEqualsWithDelta(50, (float) $this->inventory->fresh()->quantity, 0.001);
    }

    public function test_medication_data_returns_products_with_farm_scoped_inventories(): void
    {
        $otherFarm = Farm::factory()->create();
        PoultryMedicationInventory::create([
            'farm_id' => $otherFarm->id,
            'medication_product_id' => $this->product->id,
            'quantity' => 99,
            'unit_cost' => 1,
            'status' => 'available',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/medications/data");

        $response->assertOk();
        $antibiotics = collect($response->json('data'))->firstWhere('id', $this->antibiotics->id);
        $this->assertNotNull($antibiotics);
        $this->assertCount(1, $antibiotics['products']);
        $this->assertSame($this->product->id, $antibiotics['products'][0]['id']);
        $this->assertCount(1, $antibiotics['products'][0]['inventories']);
        $this->assertSame($this->inventory->id, $antibiotics['products'][0]['inventories'][0]['id']);
    }

    public function test_product_used_in_records_cannot_be_deleted(): void
    {
        $this->postRecord()->assertOk();

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson("/api/farms/{$this->farm->id}/medication-products/{$this->product->id}")
            ->assertStatus(422);

        $this->assertNotNull($this->product->fresh());
    }
}
