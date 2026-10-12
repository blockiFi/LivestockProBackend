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
            'update medication records',
            'delete medication records',
            'create medication products',
            'update medication products',
            'delete medication products',
            'view medication inventory',
            'manage medication inventory',
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

    public function test_inventory_can_be_added_for_a_product_with_no_stock(): void
    {
        $newProduct = MedicationProduct::create([
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_medication_id' => $this->vitamins->id,
            'name' => 'Vitamin AD3E',
            'manufacturer' => 'VetPharm',
            'administration_method_id' => $this->method->id,
            'pack_type' => 'bottle',
            'pack_size' => 100,
            'pack_size_unit' => 'ml',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/medication-inventory", [
                'medication_product_id' => $newProduct->id,
                'packs_received' => 20,
                'unit_cost' => 150,
                'batch_number' => 'VIT-1',
                'expiry_date' => now()->addYear()->toDateString(),
                'notes' => 'From Agro Supplies',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.product.id', $newProduct->id)
            ->assertJsonPath('data.remaining_breakdown', '20 bottles');

        $inventory = PoultryMedicationInventory::findOrFail($response->json('data.id'));
        $this->assertEqualsWithDelta(2000, (float) $inventory->quantity, 0.001);
        $this->assertEqualsWithDelta(2000, (float) $inventory->available_quantity, 0.001);
        $this->assertSame('ml', $inventory->content_unit);
        $this->assertSame('VetPharm', $inventory->manufacturer);
        $this->assertSame('From Agro Supplies', $inventory->notes);
        $this->assertNotNull($inventory->last_restocked);
    }

    public function test_inventory_cannot_be_added_for_another_farms_product(): void
    {
        $otherFarm = Farm::factory()->create();
        $foreignProduct = MedicationProduct::create([
            'farm_id' => $otherFarm->id,
            'type' => 'user',
            'poultry_medication_id' => $this->antibiotics->id,
            'name' => 'Foreign Product',
            'manufacturer' => 'Elsewhere',
            'administration_method_id' => $this->method->id,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/medication-inventory", [
                'medication_product_id' => $foreignProduct->id,
                'quantity' => 5,
                'unit_cost' => 10,
            ])
            ->assertStatus(403);
    }

    public function test_usage_keeps_available_quantity_in_sync(): void
    {
        $recordId = $this->postRecord()->assertOk()->json('data.id');

        $inventory = $this->inventory->fresh();
        $this->assertEqualsWithDelta(45, (float) $inventory->quantity, 0.001);
        $this->assertEqualsWithDelta(45, (float) $inventory->available_quantity, 0.001);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/farms/{$this->farm->id}/medication-records/{$recordId}", ['dosage' => 8])
            ->assertOk();

        $inventory = $this->inventory->fresh();
        $this->assertEqualsWithDelta(42, (float) $inventory->quantity, 0.001);
        $this->assertEqualsWithDelta(42, (float) $inventory->available_quantity, 0.001);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson("/api/farms/{$this->farm->id}/medication-records/{$recordId}")
            ->assertOk();

        $inventory = $this->inventory->fresh();
        $this->assertEqualsWithDelta(50, (float) $inventory->quantity, 0.001);
        $this->assertEqualsWithDelta(50, (float) $inventory->available_quantity, 0.001);
    }

    public function test_product_used_in_records_cannot_be_deleted(): void
    {
        $this->postRecord()->assertOk();

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson("/api/farms/{$this->farm->id}/medication-products/{$this->product->id}")
            ->assertStatus(422);

        $this->assertNotNull($this->product->fresh());
    }

    private function api()
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->token);
    }

    private function packProduct(string $packType, float $size, string $unit, string $name = 'Amprolium'): MedicationProduct
    {
        return MedicationProduct::create([
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_medication_id' => $this->antibiotics->id,
            'name' => $name,
            'manufacturer' => 'Acme',
            'administration_method_id' => $this->method->id,
            'pack_type' => $packType,
            'pack_size' => $size,
            'pack_size_unit' => $unit,
        ]);
    }

    private function addStock(MedicationProduct $product, float $packs, float $costPerPack): PoultryMedicationInventory
    {
        $id = $this->api()
            ->postJson("/api/farms/{$this->farm->id}/medication-inventory", [
                'medication_product_id' => $product->id,
                'packs_received' => $packs,
                'unit_cost' => $costPerPack,
            ])
            ->assertStatus(201)
            ->json('data.id');

        return PoultryMedicationInventory::findOrFail($id);
    }

    public function test_product_requires_pack_size(): void
    {
        $base = [
            'poultry_medication_id' => $this->antibiotics->id,
            'name' => 'No Pack',
            'manufacturer' => 'Acme',
            'administration_method_id' => $this->method->id,
        ];

        $this->api()->postJson("/api/farms/{$this->farm->id}/medication-products", $base)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pack_type', 'pack_size', 'pack_size_unit']);

        $this->api()->postJson("/api/farms/{$this->farm->id}/medication-products", array_merge($base, [
            'pack_type' => 'sachet',
            'pack_size' => 100,
            'pack_size_unit' => 'g',
        ]))
            ->assertStatus(201)
            ->assertJsonPath('data.pack_label', 'Sachet of 100 g');
    }

    public function test_legacy_product_must_set_pack_size_on_edit(): void
    {
        $url = "/api/farms/{$this->farm->id}/medication-products/{$this->product->id}";

        $this->api()->putJson($url, ['manufacturer' => 'Acme 2'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pack_type', 'pack_size', 'pack_size_unit']);

        $this->api()->putJson($url, [
            'manufacturer' => 'Acme 2',
            'pack_type' => 'tin',
            'pack_size' => 1,
            'pack_size_unit' => 'kg',
        ])->assertOk();

        $this->api()->putJson($url, ['manufacturer' => 'Acme 3'])->assertOk();
    }

    public function test_stock_cannot_be_added_without_pack_size(): void
    {
        $this->api()->postJson("/api/farms/{$this->farm->id}/medication-inventory", [
            'medication_product_id' => $this->product->id,
            'packs_received' => 2,
            'unit_cost' => 10,
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.medication_product_id.0', 'Set the pack size on this product before adding stock.');
    }

    public function test_sachet_stock_deducts_in_grams_and_costs_per_pack(): void
    {
        $product = $this->packProduct('sachet', 100, 'g');
        $batch = $this->addStock($product, 2, 500);

        $this->assertEqualsWithDelta(200, (float) $batch->quantity, 0.0001);
        $this->assertEqualsWithDelta(200, (float) $batch->total_content, 0.0001);
        $this->assertSame('2 sachets', $batch->remaining_breakdown);

        $first = $this->postRecord([
            'poultry_medication_inventory_id' => $batch->id,
            'dosage' => 20,
            'dosage_unit' => 'g',
        ])->assertOk();
        $this->assertEqualsWithDelta(100, (float) $first->json('data.cost'), 0.001);

        $batch->refresh();
        $this->assertEqualsWithDelta(180, (float) $batch->quantity, 0.0001);
        $this->assertSame('1 sachet + 80 g', $batch->remaining_breakdown);
        $this->assertSame('in_use', $batch->status);

        $this->postRecord([
            'poultry_medication_inventory_id' => $batch->id,
            'dosage' => 40,
            'dosage_unit' => 'g',
        ])->assertOk();

        $batch->refresh();
        $this->assertEqualsWithDelta(140, (float) $batch->quantity, 0.0001);
        $this->assertSame('1 sachet + 40 g', $batch->remaining_breakdown);
    }

    public function test_milligrams_are_converted_to_grams(): void
    {
        $batch = $this->addStock($this->packProduct('sachet', 100, 'g'), 1, 100);

        $this->postRecord([
            'poultry_medication_inventory_id' => $batch->id,
            'dosage' => 500,
            'dosage_unit' => 'mg',
        ])->assertOk();

        $this->assertEqualsWithDelta(99.5, (float) $batch->fresh()->quantity, 0.0001);
    }

    public function test_volume_unit_is_rejected_for_mass_product(): void
    {
        $batch = $this->addStock($this->packProduct('sachet', 100, 'g'), 1, 100);

        $this->postRecord([
            'poultry_medication_inventory_id' => $batch->id,
            'dosage' => 10,
            'dosage_unit' => 'ml',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.dosage_unit.0', 'This product is measured in g. Enter g, mg or kg.');

        $this->assertEqualsWithDelta(100, (float) $batch->fresh()->quantity, 0.0001);
    }

    public function test_litre_bottle_used_in_millilitres(): void
    {
        $batch = $this->addStock($this->packProduct('bottle', 1, 'L', 'Tylosin'), 1, 2000);
        $this->assertEqualsWithDelta(1000, (float) $batch->quantity, 0.0001);

        $this->postRecord([
            'poultry_medication_inventory_id' => $batch->id,
            'dosage' => 10,
            'dosage_unit' => 'ml',
        ])->assertOk();

        $batch->refresh();
        $this->assertEqualsWithDelta(990, (float) $batch->quantity, 0.0001);
        $this->assertSame('990 ml', $batch->remaining_breakdown);
    }

    public function test_update_and_delete_refund_base_amounts(): void
    {
        $batch = $this->addStock($this->packProduct('sachet', 100, 'g'), 2, 500);

        $recordId = $this->postRecord([
            'poultry_medication_inventory_id' => $batch->id,
            'dosage' => 20,
            'dosage_unit' => 'g',
        ])->assertOk()->json('data.id');

        $this->api()
            ->putJson("/api/farms/{$this->farm->id}/medication-records/{$recordId}", [
                'dosage' => 1,
                'dosage_unit' => 'kg',
            ])
            ->assertStatus(400);
        $this->assertEqualsWithDelta(180, (float) $batch->fresh()->quantity, 0.0001);

        $this->api()
            ->putJson("/api/farms/{$this->farm->id}/medication-records/{$recordId}", [
                'dosage' => 30000,
                'dosage_unit' => 'mg',
            ])
            ->assertOk()
            ->assertJsonPath('data.quantity', '30.0000');
        $this->assertEqualsWithDelta(170, (float) $batch->fresh()->quantity, 0.0001);

        $this->api()
            ->deleteJson("/api/farms/{$this->farm->id}/medication-records/{$recordId}")
            ->assertOk();

        $batch->refresh();
        $this->assertEqualsWithDelta(200, (float) $batch->quantity, 0.0001);
        $this->assertSame('available', $batch->status);
    }

    public function test_legacy_batch_deducts_one_to_one_regardless_of_unit(): void
    {
        $this->postRecord(['dosage' => 500, 'dosage_unit' => 'mg', 'quantity' => 5])->assertOk();

        $this->assertEqualsWithDelta(45, (float) $this->inventory->fresh()->quantity, 0.001);
    }

    public function test_batch_can_be_marked_depleted(): void
    {
        $batch = $this->addStock($this->packProduct('sachet', 100, 'g'), 2, 500);
        $this->postRecord([
            'poultry_medication_inventory_id' => $batch->id,
            'dosage' => 50,
            'dosage_unit' => 'g',
        ])->assertOk();

        $this->api()
            ->postJson("/api/farms/{$this->farm->id}/medication-inventory/{$batch->id}/deplete", [
                'reason' => 'wasted',
                'note' => 'Sachet got wet',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'depleted');

        $batch->refresh();
        $this->assertEqualsWithDelta(0, (float) $batch->quantity, 0.0001);
        $this->assertEqualsWithDelta(150, (float) $batch->written_off_quantity, 0.0001);
        $this->assertEqualsWithDelta(750, (float) $batch->written_off_value, 0.001);
        $this->assertSame('wasted', $batch->depletion_reason);
        $this->assertSame('Sachet got wet', $batch->depletion_note);
        $this->assertSame($this->user->id, (int) $batch->depleted_by);
        $this->assertNotNull($batch->depleted_at);

        $listed = collect($this->api()->getJson("/api/farms/{$this->farm->id}/medication-inventory")->assertOk()->json('data'))
            ->pluck('id');
        $this->assertNotContains($batch->id, $listed);

        $withDepleted = collect($this->api()->getJson("/api/farms/{$this->farm->id}/medication-inventory?include_depleted=1")->json('data'))
            ->pluck('id');
        $this->assertContains($batch->id, $withDepleted);

        $this->postRecord([
            'poultry_medication_inventory_id' => $batch->id,
            'dosage' => 1,
            'dosage_unit' => 'g',
        ])->assertStatus(400);

        $this->api()
            ->postJson("/api/farms/{$this->farm->id}/medication-inventory/{$batch->id}/deplete", ['reason' => 'lost'])
            ->assertStatus(422);
    }

    public function test_deplete_rejects_unknown_reason(): void
    {
        $batch = $this->addStock($this->packProduct('sachet', 100, 'g'), 1, 100);

        $this->api()
            ->postJson("/api/farms/{$this->farm->id}/medication-inventory/{$batch->id}/deplete", ['reason' => 'sold'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }
}
