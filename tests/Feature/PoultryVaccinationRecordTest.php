<?php

namespace Tests\Feature;

use App\Models\AdministrationMethod;
use App\Models\Country;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockExpenditure;
use App\Models\FlockStage;
use App\Models\Permission;
use App\Models\PoultryHouse;
use App\Models\PoultryType;
use App\Models\PoultryVaccinationRecord;
use App\Models\PoultryVaccine;
use App\Models\PoultryVaccineInventory;
use App\Models\PoultryVaccineProduct;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PoultryVaccinationRecordTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private string $token;
    private Flock $flock;
    private AdministrationMethod $method;
    private PoultryVaccine $vaccine;
    private PoultryVaccineInventory $inventory;

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
            'create vaccines',
            'view vaccination records',
            'create vaccination records',
            'update vaccination records',
            'delete vaccination records',
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

        $this->method = AdministrationMethod::create(['name' => 'Eye drop']);
        $this->vaccine = PoultryVaccine::create(['farm_id' => $this->farm->id, 'name' => 'Newcastle']);
        $product = PoultryVaccineProduct::create([
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_vaccine_id' => $this->vaccine->id,
            'name' => 'Lasota',
            'administration_method_id' => $this->method->id,
        ]);
        $this->inventory = PoultryVaccineInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_vaccine_product_id' => $product->id,
            'quantity' => 10,
            'unit_cost' => 200,
            'status' => 'available',
            'batch_number' => 'LS-1',
        ]);
    }

    private function api()
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->token);
    }

    private function postRecord(array $overrides = [])
    {
        return $this->api()->postJson("/api/farms/{$this->farm->id}/vaccination-records", array_merge([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'poultry_vaccine_id' => $this->vaccine->id,
            'poultry_vaccine_inventory_id' => $this->inventory->id,
            'date' => now()->toDateString(),
            'administered_by' => 'Vet',
            'dosage' => 1,
            'dosage_unit' => 'drop',
            'quantity' => 2,
            'administration_method_id' => $this->method->id,
        ], $overrides));
    }

    private function expenseFor(int $recordId): ?FlockExpenditure
    {
        return FlockExpenditure::where('source_type', 'vaccination_record')->where('source_id', $recordId)->first();
    }

    public function test_record_from_stock_deducts_and_books_batch_price(): void
    {
        $id = (int) $this->postRecord()->assertOk()->json('data.id');

        $this->assertEqualsWithDelta(8, (float) $this->inventory->fresh()->quantity, 0.001);
        $this->assertEqualsWithDelta(400, (float) $this->expenseFor($id)->amount, 0.001);
    }

    public function test_record_without_inventory_skips_stock_and_books_entered_cost(): void
    {
        $response = $this->postRecord([
            'poultry_vaccine_inventory_id' => null,
            'quantity' => null,
            'cost' => 2500,
        ])->assertOk()->assertJsonPath('data.poultry_vaccine_inventory_id', null);

        $id = (int) $response->json('data.id');
        $this->assertEqualsWithDelta(10, (float) $this->inventory->fresh()->quantity, 0.001);

        $expense = $this->expenseFor($id);
        $this->assertNotNull($expense);
        $this->assertSame('vaccination', $expense->category);
        $this->assertEqualsWithDelta(2500, (float) $expense->amount, 0.001);
    }

    public function test_record_without_inventory_or_cost_creates_no_expense(): void
    {
        $id = (int) $this->postRecord(['poultry_vaccine_inventory_id' => null])->assertOk()->json('data.id');

        $this->assertNull($this->expenseFor($id));
        $this->assertNull(PoultryVaccinationRecord::find($id)->cost);
    }

    public function test_entered_cost_overrides_batch_price(): void
    {
        $id = (int) $this->postRecord(['cost' => 150])->assertOk()->json('data.id');

        $this->assertEqualsWithDelta(8, (float) $this->inventory->fresh()->quantity, 0.001);
        $this->assertEqualsWithDelta(150, (float) $this->expenseFor($id)->amount, 0.001);
    }

    public function test_quantity_is_required_when_drawing_from_stock(): void
    {
        $this->postRecord(['quantity' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');
    }

    public function test_batch_from_another_farm_is_rejected(): void
    {
        $other = Farm::factory()->create();
        $foreign = PoultryVaccineInventory::create([
            'farm_id' => $other->id,
            'poultry_vaccine_product_id' => $this->inventory->poultry_vaccine_product_id,
            'quantity' => 10,
            'unit_cost' => 1,
            'status' => 'available',
        ]);

        $this->postRecord(['poultry_vaccine_inventory_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('poultry_vaccine_inventory_id');
        $this->assertEqualsWithDelta(10, (float) $foreign->fresh()->quantity, 0.001);
    }

    public function test_update_moves_stock_between_batches_and_off_stock(): void
    {
        $id = (int) $this->postRecord()->json('data.id');

        $this->api()->putJson("/api/farms/{$this->farm->id}/vaccination-records/{$id}", ['quantity' => 3])->assertOk();
        $this->assertEqualsWithDelta(7, (float) $this->inventory->fresh()->quantity, 0.001);
        $this->assertEqualsWithDelta(600, (float) $this->expenseFor($id)->amount, 0.001);

        $this->api()->putJson("/api/farms/{$this->farm->id}/vaccination-records/{$id}", [
            'poultry_vaccine_inventory_id' => null,
        ])->assertOk();
        $this->assertEqualsWithDelta(10, (float) $this->inventory->fresh()->quantity, 0.001);
        $this->assertNull($this->expenseFor($id));
    }

    public function test_deleting_a_record_without_inventory_removes_its_expense(): void
    {
        $id = (int) $this->postRecord(['poultry_vaccine_inventory_id' => null, 'cost' => 800])->json('data.id');

        $this->api()->deleteJson("/api/farms/{$this->farm->id}/vaccination-records/{$id}")->assertOk();

        $this->assertNull($this->expenseFor($id));
    }

    public function test_vaccine_can_be_added_with_just_a_name(): void
    {
        $this->api()->postJson("/api/farms/{$this->farm->id}/vaccines", ['name' => 'Gumboro'])
            ->assertCreated()
            ->assertJsonPath('data.farm_id', $this->farm->id);
    }

    public function test_vaccine_name_is_unique_per_farm_only(): void
    {
        $this->api()->postJson("/api/farms/{$this->farm->id}/vaccines", ['name' => 'Newcastle'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $other = Farm::factory()->create();
        PoultryVaccine::create(['farm_id' => $other->id, 'name' => 'Gumboro']);

        $this->api()->postJson("/api/farms/{$this->farm->id}/vaccines", ['name' => 'Gumboro'])
            ->assertCreated();
    }
}
