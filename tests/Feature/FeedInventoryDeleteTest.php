<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\Permission;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryFeedType;
use App\Models\PoultryFeedUsage;
use App\Models\PoultryType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedInventoryDeleteTest extends TestCase
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

        foreach (['create feed inventories', 'delete feed inventories', 'view feed inventories'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $ownerRole = Role::create([
            'name' => 'owner',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $ownerRole->givePermissionTo([
            'create feed inventories',
            'delete feed inventories',
            'view feed inventories',
        ]);

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

        $this->flock = Flock::factory()->create([
            'farm_id' => $this->farm->id,
        ]);
    }

    public function test_can_delete_unused_new_inventory_batch(): void
    {
        $inventory = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 100,
            'available_quantity' => 100,
            'unit_cost' => 2.5,
            'status' => 'available',
            'batch_number' => 'NEW-UNUSED',
            'created_by' => $this->user->id,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson("/api/farms/{$this->farm->id}/feed-inventories/{$inventory->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('poultry_feed_inventories', ['id' => $inventory->id]);
    }

    public function test_can_delete_new_batch_that_only_has_auto_settlement_usages(): void
    {
        $negativeInventory = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => -10,
            'available_quantity' => 50,
            'unit_cost' => 2.5,
            'status' => 'depleted',
            'batch_number' => 'NEG-OLD',
            'created_by' => $this->user->id,
        ]);

        PoultryFeedUsage::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_inventory_id' => $negativeInventory->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'flock_id' => $this->flock->id,
            'quantity' => 20,
            'unit_cost' => 2.5,
            'usage_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feed-inventories", [
                'poultry_feed_type_id' => $this->feedType->id,
                'quantity' => 30,
                'unit_cost' => 3.0,
                'batch_number' => 'NEW-TOPUP',
            ])
            ->assertStatus(201);

        $newInventoryId = (int) $response->json('data.id');
        $this->assertTrue((bool) $response->json('data.can_delete'));

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson("/api/farms/{$this->farm->id}/feed-inventories/{$newInventoryId}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('poultry_feed_inventories', ['id' => $newInventoryId]);
        $this->assertEqualsWithDelta(-10, (float) $negativeInventory->fresh()->quantity, 0.01);
    }

    public function test_cannot_delete_batch_with_real_flock_usage(): void
    {
        $inventory = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 80,
            'available_quantity' => 100,
            'unit_cost' => 2.5,
            'status' => 'available',
            'batch_number' => 'USED-BATCH',
            'created_by' => $this->user->id,
        ]);

        PoultryFeedUsage::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_inventory_id' => $inventory->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'flock_id' => $this->flock->id,
            'quantity' => 20,
            'unit_cost' => 2.5,
            'usage_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson("/api/farms/{$this->farm->id}/feed-inventories/{$inventory->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('poultry_feed_inventories', ['id' => $inventory->id]);
    }
}
