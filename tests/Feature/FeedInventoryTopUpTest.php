<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Farm;
use App\Models\Permission;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryFeedType;
use App\Models\PoultryType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedInventoryTopUpTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private string $token;
    private PoultryFeedType $feedType;

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
            'name' => 'create feed inventories',
            'guard_name' => 'api',
        ]);
        $deletePermission = Permission::firstOrCreate([
            'name' => 'delete feed inventories',
            'guard_name' => 'api',
        ]);
        $updatePermission = Permission::firstOrCreate([
            'name' => 'update feed inventories',
            'guard_name' => 'api',
        ]);

        $ownerRole = Role::create([
            'name' => 'owner',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $ownerRole->givePermissionTo([$permission, $deletePermission, $updatePermission]);

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
    }

    public function test_new_inventory_tops_up_negative_inventory_of_same_feed_type(): void
    {
        $negativeInventory = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => -13.4,
            'available_quantity' => 100,
            'unit_cost' => 2.5,
            'status' => 'depleted',
            'batch_number' => 'NEG-BATCH',
            'created_by' => $this->user->id,
        ]);

        $flock = \App\Models\Flock::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        // Overdraft usage that caused the negative balance (plus earlier in-stock usage).
        \App\Models\PoultryFeedUsage::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_inventory_id' => $negativeInventory->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'flock_id' => $flock->id,
            'quantity' => 100,
            'unit_cost' => 2.5,
            'usage_date' => now()->subDay()->toDateString(),
            'created_by' => $this->user->id,
        ]);
        $overdraftUsage = \App\Models\PoultryFeedUsage::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_inventory_id' => $negativeInventory->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'flock_id' => $flock->id,
            'quantity' => 13.4,
            'unit_cost' => 2.5,
            'usage_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feed-inventories", [
                'poultry_feed_type_id' => $this->feedType->id,
                'quantity' => 100,
                'unit_cost' => 3.0,
                'batch_number' => 'NEW-BATCH',
            ])
            ->assertStatus(201);

        $newInventoryId = (int) $response->json('data.id');

        $this->assertEqualsWithDelta(0, (float) $negativeInventory->fresh()->quantity, 0.01);
        $this->assertEqualsWithDelta(86.6, (float) $response->json('data.quantity'), 0.01);
        $this->assertEquals(100, (float) $response->json('data.available_quantity'));

        // Overdraft usage should now be recorded against the new batch.
        $this->assertEquals($newInventoryId, (int) $overdraftUsage->fresh()->poultry_feed_inventory_id);
        $this->assertEqualsWithDelta(3.0, (float) $overdraftUsage->fresh()->unit_cost, 0.01);
        $this->assertEqualsWithDelta(
            13.4,
            (float) \App\Models\PoultryFeedUsage::where('poultry_feed_inventory_id', $newInventoryId)->sum('quantity'),
            0.01
        );
        $this->assertEqualsWithDelta(
            100,
            (float) \App\Models\PoultryFeedUsage::where('poultry_feed_inventory_id', $negativeInventory->id)->sum('quantity'),
            0.01
        );
    }

    public function test_top_up_splits_partial_usage_onto_new_inventory(): void
    {
        $negativeInventory = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => -5,
            'available_quantity' => 20,
            'unit_cost' => 2.5,
            'status' => 'depleted',
            'batch_number' => 'NEG-SPLIT',
            'created_by' => $this->user->id,
        ]);

        $flock = \App\Models\Flock::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        $usage = \App\Models\PoultryFeedUsage::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_inventory_id' => $negativeInventory->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'flock_id' => $flock->id,
            'quantity' => 25,
            'unit_cost' => 2.5,
            'usage_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feed-inventories", [
                'poultry_feed_type_id' => $this->feedType->id,
                'quantity' => 5,
                'unit_cost' => 4.0,
                'batch_number' => 'COVER-SPLIT',
            ])
            ->assertStatus(201);

        $newInventoryId = (int) $response->json('data.id');

        $this->assertEqualsWithDelta(0, (float) $negativeInventory->fresh()->quantity, 0.01);
        $this->assertEqualsWithDelta(0, (float) $response->json('data.quantity'), 0.01);

        $this->assertEqualsWithDelta(20, (float) $usage->fresh()->quantity, 0.01);
        $this->assertEquals($negativeInventory->id, (int) $usage->fresh()->poultry_feed_inventory_id);

        $moved = \App\Models\PoultryFeedUsage::where('poultry_feed_inventory_id', $newInventoryId)->first();
        $this->assertNotNull($moved);
        $this->assertEqualsWithDelta(5, (float) $moved->quantity, 0.01);
        $this->assertEquals($flock->id, (int) $moved->flock_id);
        $this->assertEqualsWithDelta(4.0, (float) $moved->unit_cost, 0.01);
    }

    public function test_new_inventory_tops_up_multiple_negative_batches_fifo(): void
    {
        $olderNegative = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => -10,
            'available_quantity' => 50,
            'unit_cost' => 2.5,
            'status' => 'depleted',
            'batch_number' => 'OLD-NEG',
            'created_by' => $this->user->id,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $newerNegative = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => -5,
            'available_quantity' => 30,
            'unit_cost' => 2.5,
            'status' => 'depleted',
            'batch_number' => 'NEW-NEG',
            'created_by' => $this->user->id,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $flock = \App\Models\Flock::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        // Overdraft usages exist only on the older batch; newer batch has none.
        \App\Models\PoultryFeedUsage::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_inventory_id' => $olderNegative->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'flock_id' => $flock->id,
            'quantity' => 60,
            'unit_cost' => 2.5,
            'usage_date' => now()->subDays(2)->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feed-inventories", [
                'poultry_feed_type_id' => $this->feedType->id,
                'quantity' => 12,
                'unit_cost' => 2.5,
                'batch_number' => 'TOP-UP-BATCH',
            ])
            ->assertStatus(201);

        $newInventoryId = (int) $response->json('data.id');

        $this->assertEqualsWithDelta(0, (float) $olderNegative->fresh()->quantity, 0.01);
        $this->assertEqualsWithDelta(-3, (float) $newerNegative->fresh()->quantity, 0.01);
        $this->assertEqualsWithDelta(0, (float) $response->json('data.quantity'), 0.01);

        // Full 12kg taken from the new batch must appear in its usage history:
        // 10kg reassigned from the older overdraft + 2kg settlement for the newer batch.
        $this->assertEqualsWithDelta(
            12,
            (float) \App\Models\PoultryFeedUsage::where('poultry_feed_inventory_id', $newInventoryId)->sum('quantity'),
            0.01
        );
    }

    public function test_top_up_records_settlement_usage_when_negative_batch_has_no_usages(): void
    {
        $negativeInventory = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => -8,
            'available_quantity' => 40,
            'unit_cost' => 2.5,
            'status' => 'depleted',
            'batch_number' => 'NEG-NO-USAGE',
            'created_by' => $this->user->id,
        ]);

        // Farm has a flock so settlement usage can satisfy flock_id FK.
        \App\Models\Flock::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feed-inventories", [
                'poultry_feed_type_id' => $this->feedType->id,
                'quantity' => 20,
                'unit_cost' => 3.25,
                'batch_number' => 'COVER-NO-USAGE',
            ])
            ->assertStatus(201);

        $newInventoryId = (int) $response->json('data.id');

        $this->assertEqualsWithDelta(0, (float) $negativeInventory->fresh()->quantity, 0.01);
        $this->assertEqualsWithDelta(12, (float) $response->json('data.quantity'), 0.01);

        $settlement = \App\Models\PoultryFeedUsage::where('poultry_feed_inventory_id', $newInventoryId)->get();
        $this->assertCount(1, $settlement);
        $this->assertEqualsWithDelta(8, (float) $settlement->first()->quantity, 0.01);
        $this->assertEqualsWithDelta(3.25, (float) $settlement->first()->unit_cost, 0.01);
    }

    public function test_new_inventory_does_not_top_up_different_feed_type(): void
    {
        $otherFeedType = PoultryFeedType::create([
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_type_id' => $this->feedType->poultry_type_id,
            'name' => 'Layer Feed',
            'description' => 'Other feed',
        ]);

        $negativeInventory = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $otherFeedType->id,
            'quantity' => -20,
            'available_quantity' => 80,
            'unit_cost' => 2.5,
            'status' => 'depleted',
            'batch_number' => 'OTHER-NEG',
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feed-inventories", [
                'poultry_feed_type_id' => $this->feedType->id,
                'quantity' => 100,
                'unit_cost' => 2.5,
                'batch_number' => 'UNRELATED-BATCH',
            ])
            ->assertStatus(201);

        $this->assertEquals(-20, (float) $negativeInventory->fresh()->quantity);
        $this->assertEquals(100, (float) $response->json('data.quantity'));
    }

    public function test_inventory_without_usage_records_can_be_deleted(): void
    {
        $inventory = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 50,
            'available_quantity' => 100,
            'unit_cost' => 2.5,
            'status' => 'available',
            'batch_number' => 'UNUSED-BATCH',
            'created_by' => $this->user->id,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson("/api/farms/{$this->farm->id}/feed-inventories/{$inventory->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('poultry_feed_inventories', ['id' => $inventory->id]);
    }

    public function test_empty_inventory_without_usage_records_can_be_deleted(): void
    {
        $inventory = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 0,
            'available_quantity' => 100,
            'unit_cost' => 2.5,
            'status' => 'depleted',
            'batch_number' => 'FULLY-USED-BATCH',
            'created_by' => $this->user->id,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson("/api/farms/{$this->farm->id}/feed-inventories/{$inventory->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('poultry_feed_inventories', ['id' => $inventory->id]);
    }

    public function test_inventory_with_usage_records_cannot_be_deleted(): void
    {
        $inventory = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 50,
            'available_quantity' => 100,
            'unit_cost' => 2.5,
            'status' => 'available',
            'batch_number' => 'USED-BATCH',
            'created_by' => $this->user->id,
        ]);

        \App\Models\PoultryFeedUsage::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_inventory_id' => $inventory->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'flock_id' => \App\Models\Flock::factory()->create([
                'farm_id' => $this->farm->id,
            ])->id,
            'quantity' => 10,
            'unit_cost' => 2.5,
            'usage_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson("/api/farms/{$this->farm->id}/feed-inventories/{$inventory->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('poultry_feed_inventories', ['id' => $inventory->id]);
    }

    public function test_inventory_unit_cost_can_be_updated(): void
    {
        $inventory = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 100,
            'available_quantity' => 100,
            'unit_cost' => 2.5,
            'status' => 'available',
            'batch_number' => 'COST-BATCH',
            'created_by' => $this->user->id,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/farms/{$this->farm->id}/feed-inventories/{$inventory->id}", [
                'unit_cost' => 4.75,
            ])
            ->assertStatus(200);

        $this->assertEqualsWithDelta(4.75, (float) $inventory->fresh()->unit_cost, 0.001);
    }
}
