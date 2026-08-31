<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockExpenditure;
use App\Models\FlockStage;
use App\Models\Permission;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryFeedType;
use App\Models\PoultryHouse;
use App\Models\PoultryType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedInventoryCloseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private string $token;
    private PoultryFeedType $feedType;
    private Flock $flock;

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

        $permission = Permission::firstOrCreate([
            'name' => 'update feed inventories',
            'guard_name' => 'api',
        ]);

        $ownerRole = Role::create([
            'name' => 'owner',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $ownerRole->givePermissionTo($permission);

        $this->farm->users()->attach($this->user->id);
        $this->user->assignRole($ownerRole);

        $poultryType = PoultryType::factory()->create();

        $this->feedType = PoultryFeedType::create([
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_type_id' => $poultryType->id,
            'name' => 'Grower Feed',
            'description' => 'Test feed',
        ]);

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
            'quantity' => 500,
            'status' => 'active',
        ]);
    }

    public function test_inventory_can_be_closed_and_remaining_stock_recorded_as_damaged(): void
    {
        $inventory = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 43,
            'available_quantity' => 100,
            'unit_cost' => 2.5,
            'status' => 'in_use',
            'batch_number' => 'CLOSE-ME',
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feed-inventories/{$inventory->id}/close", [
                'notes' => 'Mould damage',
            ])
            ->assertStatus(200);

        $inventory->refresh();

        $this->assertEquals('closed', $inventory->status);
        $this->assertEqualsWithDelta(43, (float) $inventory->damaged_quantity, 0.01);
        $this->assertEqualsWithDelta(0, (float) $inventory->quantity, 0.01);
        $this->assertEquals('Mould damage', $inventory->close_notes);
        $this->assertNotNull($inventory->closed_at);
        $this->assertEquals($this->user->id, $inventory->closed_by);
        $this->assertEqualsWithDelta(43, (float) $response->json('data.damaged_quantity'), 0.01);
    }

    public function test_close_with_flock_allocates_damaged_cost_to_flock_expenditure(): void
    {
        $inventory = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 20,
            'available_quantity' => 50,
            'unit_cost' => 3.5,
            'status' => 'in_use',
            'batch_number' => 'ALLOCATE-ME',
            'created_by' => $this->user->id,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feed-inventories/{$inventory->id}/close", [
                'notes' => 'Spillage',
                'flock_id' => $this->flock->id,
            ])
            ->assertStatus(200);

        $inventory->refresh();

        $this->assertEquals('closed', $inventory->status);
        $this->assertEquals($this->flock->id, $inventory->allocated_flock_id);

        $expenditure = FlockExpenditure::where('source_type', 'feed_inventory_close')
            ->where('source_id', $inventory->id)
            ->first();

        $this->assertNotNull($expenditure);
        $this->assertEquals($this->flock->id, $expenditure->flock_id);
        $this->assertEquals('feed', $expenditure->category);
        $this->assertEqualsWithDelta(70, (float) $expenditure->amount, 0.01);
        $this->assertStringContainsString('Damaged feed write-off', $expenditure->description);
    }

    public function test_close_rejects_flock_from_another_farm(): void
    {
        $otherFarm = Farm::factory()->create([
            'created_by' => $this->user->id,
            'country_id' => Country::factory()->create()->id,
        ]);

        $otherFlock = Flock::factory()->create([
            'farm_id' => $otherFarm->id,
            'poultry_type_id' => $this->flock->poultry_type_id,
            'flock_stage_id' => $this->flock->flock_stage_id,
            'house_id' => $this->flock->house_id,
        ]);

        $inventory = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 10,
            'available_quantity' => 10,
            'unit_cost' => 2.5,
            'status' => 'available',
            'batch_number' => 'WRONG-FLOCK',
            'created_by' => $this->user->id,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feed-inventories/{$inventory->id}/close", [
                'flock_id' => $otherFlock->id,
            ])
            ->assertStatus(422);
    }

    public function test_cannot_close_inventory_with_no_remaining_stock(): void
    {
        $inventory = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 0,
            'available_quantity' => 100,
            'unit_cost' => 2.5,
            'status' => 'closed',
            'batch_number' => 'EMPTY',
            'created_by' => $this->user->id,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feed-inventories/{$inventory->id}/close")
            ->assertStatus(422);

        $this->assertEquals('closed', $inventory->fresh()->status);
    }

    public function test_inventory_auto_closes_when_quantity_reaches_zero(): void
    {
        $inventory = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 10,
            'available_quantity' => 10,
            'unit_cost' => 2.5,
            'status' => 'available',
            'batch_number' => 'AUTO-CLOSE',
            'created_by' => $this->user->id,
        ]);

        \App\Services\FeedUsageInventoryService::deductFromInventory($inventory, 10);

        $inventory->refresh();

        $this->assertEqualsWithDelta(0, (float) $inventory->quantity, 0.01);
        $this->assertEquals('closed', $inventory->status);
        $this->assertNotNull($inventory->closed_at);
        $this->assertEquals('Automatically closed — stock fully used', $inventory->close_notes);
        $this->assertEqualsWithDelta(0, (float) $inventory->damaged_quantity, 0.01);
    }

    public function test_negative_inventory_stays_depleted_not_closed(): void
    {
        $inventory = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 5,
            'available_quantity' => 5,
            'unit_cost' => 2.5,
            'status' => 'available',
            'batch_number' => 'NEGATIVE',
            'created_by' => $this->user->id,
        ]);

        \App\Services\FeedUsageInventoryService::deductFromInventory($inventory, 8);

        $inventory->refresh();

        $this->assertEqualsWithDelta(-3, (float) $inventory->quantity, 0.01);
        $this->assertEquals('depleted', $inventory->status);
    }

    public function test_cannot_close_inventory_twice(): void
    {
        $inventory = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 20,
            'available_quantity' => 20,
            'unit_cost' => 2.5,
            'status' => 'available',
            'batch_number' => 'ALREADY-CLOSED',
            'created_by' => $this->user->id,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feed-inventories/{$inventory->id}/close")
            ->assertStatus(200);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feed-inventories/{$inventory->id}/close")
            ->assertStatus(422);
    }
}
