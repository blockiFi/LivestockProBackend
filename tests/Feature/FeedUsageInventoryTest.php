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
use App\Models\PoultryFeedUsage;
use App\Models\PoultryHouse;
use App\Models\PoultryType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FeedUsageInventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private string $token;
    private Flock $flock;
    private PoultryFeedInventory $inventory;

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
            'view feed inventories',
            'update feed inventories',
            'manage feed inventory',
            'view feed usages',
            'create feed usages',
            'update feed usages',
            'delete feed usages',
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

        $feedType = PoultryFeedType::create([
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_type_id' => $poultryType->id,
            'name' => 'Grower Feed',
            'description' => 'Test feed',
        ]);

        $this->inventory = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $feedType->id,
            'quantity' => 100,
            'unit_cost' => 2.5,
            'status' => 'available',
            'batch_number' => 'BATCH-FEED',
        ]);
    }

    public function test_deleting_feed_usage_restores_inventory(): void
    {
        $usage = PoultryFeedUsage::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_inventory_id' => $this->inventory->id,
            'poultry_feed_type_id' => $this->inventory->poultry_feed_type_id,
            'flock_id' => $this->flock->id,
            'quantity' => 10,
            'unit_cost' => 2.5,
            'usage_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $this->inventory->update(['quantity' => 90]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson("/api/farms/{$this->farm->id}/feed-usages/{$usage->id}")
            ->assertStatus(200);

        $this->assertEquals(100.0, (float) $this->inventory->fresh()->quantity);
        $this->assertSoftDeleted('poultry_feed_usages', ['id' => $usage->id]);
    }

    public function test_updating_feed_usage_quantity_adjusts_inventory(): void
    {
        $usage = PoultryFeedUsage::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_inventory_id' => $this->inventory->id,
            'poultry_feed_type_id' => $this->inventory->poultry_feed_type_id,
            'flock_id' => $this->flock->id,
            'quantity' => 10,
            'unit_cost' => 2.5,
            'usage_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $this->inventory->update(['quantity' => 90]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/farms/{$this->farm->id}/feed-usages/{$usage->id}", [
                'quantity' => 15,
            ])
            ->assertStatus(200);

        $this->assertEquals(85.0, (float) $this->inventory->fresh()->quantity);
        $this->assertEquals(15.0, (float) $usage->fresh()->quantity);
    }

    public function test_reducing_feed_usage_quantity_returns_stock_to_inventory(): void
    {
        $usage = PoultryFeedUsage::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_inventory_id' => $this->inventory->id,
            'poultry_feed_type_id' => $this->inventory->poultry_feed_type_id,
            'flock_id' => $this->flock->id,
            'quantity' => 20,
            'unit_cost' => 2.5,
            'usage_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $this->inventory->update(['quantity' => 80]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/farms/{$this->farm->id}/feed-usages/{$usage->id}", [
                'quantity' => 12,
            ])
            ->assertStatus(200);

        $this->assertEquals(88.0, (float) $this->inventory->fresh()->quantity);
        $this->assertEquals(12.0, (float) $usage->fresh()->quantity);
    }

    public function test_feed_usages_can_be_listed_by_inventory_with_relations(): void
    {
        $olderDate = now()->subDays(2)->toDateString();
        $newerDate = now()->subDay()->toDateString();

        PoultryFeedUsage::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_inventory_id' => $this->inventory->id,
            'poultry_feed_type_id' => $this->inventory->poultry_feed_type_id,
            'flock_id' => $this->flock->id,
            'quantity' => 5,
            'unit_cost' => 2.5,
            'usage_date' => $olderDate,
            'created_by' => $this->user->id,
        ]);

        PoultryFeedUsage::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_inventory_id' => $this->inventory->id,
            'poultry_feed_type_id' => $this->inventory->poultry_feed_type_id,
            'flock_id' => $this->flock->id,
            'quantity' => 8,
            'unit_cost' => 2.5,
            'usage_date' => $newerDate,
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/feed-usages?feed_inventory_id={$this->inventory->id}")
            ->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertEquals($newerDate, \Carbon\Carbon::parse($data[0]['usage_date'])->toDateString());
        $this->assertEquals($olderDate, \Carbon\Carbon::parse($data[1]['usage_date'])->toDateString());
        $this->assertEquals($this->flock->name, $data[0]['flock']['name']);
        $this->assertEquals($this->user->name, $data[0]['creator']['name']);
    }

    public function test_feed_usages_by_inventory_returns_404_for_other_farm_inventory(): void
    {
        $otherFarm = Farm::factory()->create([
            'created_by' => $this->user->id,
            'country_id' => $this->farm->country_id,
        ]);

        $otherInventory = PoultryFeedInventory::create([
            'farm_id' => $otherFarm->id,
            'poultry_feed_type_id' => $this->inventory->poultry_feed_type_id,
            'quantity' => 50,
            'unit_cost' => 2.5,
            'status' => 'available',
            'batch_number' => 'OTHER-BATCH',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/feed-usages?feed_inventory_id={$otherInventory->id}")
            ->assertStatus(404);
    }

    public function test_feed_usage_can_exceed_available_inventory_and_go_negative(): void
    {
        $this->inventory->update(['quantity' => 43]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feed-usages", [
                'poultry_feed_inventory_id' => $this->inventory->id,
                'poultry_feed_type_id' => $this->inventory->poultry_feed_type_id,
                'flock_id' => $this->flock->id,
                'quantity' => 56.4,
                'unit_cost' => 2.5,
                'usage_date' => '2025-10-08',
            ])
            ->assertStatus(201);

        $this->assertEqualsWithDelta(-13.4, (float) $this->inventory->fresh()->quantity, 0.01);
        $this->assertDatabaseHas('poultry_feed_usages', [
            'flock_id' => $this->flock->id,
            'poultry_feed_inventory_id' => $this->inventory->id,
            'quantity' => 56.4,
        ]);
    }

    public function test_moving_feed_usage_to_another_inventory_adjusts_both_batches(): void
    {
        $destination = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->inventory->poultry_feed_type_id,
            'quantity' => 50,
            'unit_cost' => 3.0,
            'status' => 'available',
            'batch_number' => 'BATCH-DEST',
        ]);

        $usage = PoultryFeedUsage::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_inventory_id' => $this->inventory->id,
            'poultry_feed_type_id' => $this->inventory->poultry_feed_type_id,
            'flock_id' => $this->flock->id,
            'quantity' => 10,
            'unit_cost' => 2.5,
            'usage_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $this->inventory->update(['quantity' => 90]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/farms/{$this->farm->id}/feed-usages/{$usage->id}", [
                'poultry_feed_inventory_id' => $destination->id,
            ])
            ->assertStatus(200);

        $this->assertEquals(100.0, (float) $this->inventory->fresh()->quantity);
        $this->assertEquals(40.0, (float) $destination->fresh()->quantity);
        $this->assertEquals($destination->id, (int) $usage->fresh()->poultry_feed_inventory_id);
        $this->assertEquals(3.0, (float) $usage->fresh()->unit_cost);
    }

    public function test_partial_move_splits_usage_between_inventories(): void
    {
        $destination = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->inventory->poultry_feed_type_id,
            'quantity' => 50,
            'unit_cost' => 3.0,
            'status' => 'available',
            'batch_number' => 'BATCH-SPLIT',
        ]);

        $usage = PoultryFeedUsage::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_inventory_id' => $this->inventory->id,
            'poultry_feed_type_id' => $this->inventory->poultry_feed_type_id,
            'flock_id' => $this->flock->id,
            'quantity' => 35,
            'unit_cost' => 2.5,
            'usage_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $this->inventory->update(['quantity' => 65]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/farms/{$this->farm->id}/feed-usages/{$usage->id}", [
                'poultry_feed_inventory_id' => $destination->id,
                'move_quantity' => 10,
            ])
            ->assertStatus(200);

        $response->assertJsonPath('data.usage.quantity', '25.00');
        $response->assertJsonPath('data.split_usage.quantity', '10.00');
        $response->assertJsonPath('data.split_usage.poultry_feed_inventory_id', $destination->id);

        $this->assertEquals(75.0, (float) $this->inventory->fresh()->quantity);
        $this->assertEquals(40.0, (float) $destination->fresh()->quantity);
        $this->assertEquals($this->inventory->id, (int) $usage->fresh()->poultry_feed_inventory_id);
        $this->assertEquals(25.0, (float) $usage->fresh()->quantity);

        $this->assertDatabaseHas('poultry_feed_usages', [
            'poultry_feed_inventory_id' => $destination->id,
            'quantity' => 10,
            'flock_id' => $this->flock->id,
        ]);
    }

    public function test_force_expenditure_creates_missing_expenditure_for_usage(): void
    {
        $usage = PoultryFeedUsage::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_inventory_id' => $this->inventory->id,
            'poultry_feed_type_id' => $this->inventory->poultry_feed_type_id,
            'flock_id' => $this->flock->id,
            'quantity' => 12,
            'unit_cost' => 2.5,
            'usage_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $this->assertDatabaseMissing('flock_expenditures', [
            'source_type' => 'feed_usage',
            'source_id' => $usage->id,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feed-usages/{$usage->id}/force-expenditure")
            ->assertStatus(201)
            ->assertJsonPath('data.created', true)
            ->assertJsonPath('data.has_expenditure', true);

        $this->assertDatabaseHas('flock_expenditures', [
            'flock_id' => $this->flock->id,
            'source_type' => 'feed_usage',
            'source_id' => $usage->id,
            'amount' => 30.00,
            'category' => 'feed',
        ]);
    }

    public function test_force_expenditure_reports_already_recorded(): void
    {
        $usage = PoultryFeedUsage::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_inventory_id' => $this->inventory->id,
            'poultry_feed_type_id' => $this->inventory->poultry_feed_type_id,
            'flock_id' => $this->flock->id,
            'quantity' => 5,
            'unit_cost' => 2.5,
            'usage_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        \App\Models\FlockExpenditure::recordFromFeedUsage($usage);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feed-usages/{$usage->id}/force-expenditure")
            ->assertStatus(200)
            ->assertJsonPath('data.created', false)
            ->assertJsonPath('data.has_expenditure', true);

        $this->assertEquals(
            1,
            \App\Models\FlockExpenditure::where('source_type', 'feed_usage')->where('source_id', $usage->id)->count()
        );
    }

    public function test_feed_usages_by_inventory_include_has_expenditure_flag(): void
    {
        $withExpenditure = PoultryFeedUsage::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_inventory_id' => $this->inventory->id,
            'poultry_feed_type_id' => $this->inventory->poultry_feed_type_id,
            'flock_id' => $this->flock->id,
            'quantity' => 4,
            'unit_cost' => 2.5,
            'usage_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);
        \App\Models\FlockExpenditure::recordFromFeedUsage($withExpenditure);

        $withoutExpenditure = PoultryFeedUsage::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_inventory_id' => $this->inventory->id,
            'poultry_feed_type_id' => $this->inventory->poultry_feed_type_id,
            'flock_id' => $this->flock->id,
            'quantity' => 3,
            'unit_cost' => 2.5,
            'usage_date' => now()->subDay()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/feed-usages?feed_inventory_id={$this->inventory->id}")
            ->assertStatus(200);

        $byId = collect($response->json('data'))->keyBy('id');
        $this->assertTrue($byId[$withExpenditure->id]['has_expenditure']);
        $this->assertFalse($byId[$withoutExpenditure->id]['has_expenditure']);
    }

    public function test_feed_inventory_list_includes_last_usage_date(): void
    {
        $olderDate = now()->subDays(5)->toDateString();
        $latestDate = now()->subDay()->toDateString();

        PoultryFeedUsage::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_inventory_id' => $this->inventory->id,
            'poultry_feed_type_id' => $this->inventory->poultry_feed_type_id,
            'flock_id' => $this->flock->id,
            'quantity' => 2,
            'unit_cost' => 2.5,
            'usage_date' => $olderDate,
            'created_by' => $this->user->id,
        ]);

        PoultryFeedUsage::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_inventory_id' => $this->inventory->id,
            'poultry_feed_type_id' => $this->inventory->poultry_feed_type_id,
            'flock_id' => $this->flock->id,
            'quantity' => 3,
            'unit_cost' => 2.5,
            'usage_date' => $latestDate,
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/feed-inventories")
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('id', $this->inventory->id);
        $this->assertNotNull($row);
        $this->assertEquals($latestDate, \Carbon\Carbon::parse($row['last_usage_date'])->toDateString());
    }

    public function test_feed_usage_without_inventory_auto_creates_zero_cost_overdraft_batch(): void
    {
        $feedTypeId = $this->inventory->poultry_feed_type_id;
        $this->inventory->delete();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/farms/{$this->farm->id}/feed-usages", [
                'poultry_feed_type_id' => $feedTypeId,
                'flock_id' => $this->flock->id,
                'quantity' => 25,
                'usage_date' => now()->toDateString(),
            ]);

        $response->assertCreated();

        $usageInventoryId = (int) $response->json('data.poultry_feed_inventory_id');
        $this->assertGreaterThan(0, $usageInventoryId);

        $created = PoultryFeedInventory::find($usageInventoryId);
        $this->assertNotNull($created);
        $this->assertEquals(0.0, (float) $created->unit_cost);
        $this->assertEquals(-25.0, (float) $created->quantity);
        $this->assertEquals('depleted', $created->status);
        $this->assertStringStartsWith('OVERDRAFT-', (string) $created->batch_number);
    }

    public function test_can_transfer_stock_to_compensate_negative_inventory(): void
    {
        $negative = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->inventory->poultry_feed_type_id,
            'quantity' => -20,
            'available_quantity' => 0,
            'unit_cost' => 0,
            'status' => 'depleted',
            'batch_number' => 'OVERDRAFT-TEST',
            'created_by' => $this->user->id,
        ]);

        PoultryFeedUsage::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_inventory_id' => $negative->id,
            'poultry_feed_type_id' => $negative->poultry_feed_type_id,
            'flock_id' => $this->flock->id,
            'quantity' => 20,
            'unit_cost' => 0,
            'usage_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/farms/{$this->farm->id}/feed-inventories/{$negative->id}/transfer", [
                'from_inventory_id' => $this->inventory->id,
                'quantity' => 20,
            ])
            ->assertOk()
            ->assertJsonPath('data.transferred_quantity', 20);

        $this->assertEquals(0.0, (float) $negative->fresh()->quantity);
        $this->assertEquals(80.0, (float) $this->inventory->fresh()->quantity);
    }

    public function test_transfer_rejects_cross_feed_type_and_over_source_quantity(): void
    {
        $otherType = PoultryFeedType::create([
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_type_id' => $this->flock->poultry_type_id,
            'name' => 'Finisher Feed',
            'description' => 'Other type',
        ]);

        $otherInventory = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $otherType->id,
            'quantity' => 50,
            'unit_cost' => 3,
            'status' => 'available',
            'batch_number' => 'OTHER-TYPE',
        ]);

        $negative = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->inventory->poultry_feed_type_id,
            'quantity' => -10,
            'unit_cost' => 0,
            'status' => 'depleted',
            'batch_number' => 'OVERDRAFT-X',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/farms/{$this->farm->id}/feed-inventories/{$negative->id}/transfer", [
                'from_inventory_id' => $otherInventory->id,
                'quantity' => 5,
            ])
            ->assertStatus(422);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/farms/{$this->farm->id}/feed-inventories/{$negative->id}/transfer", [
                'from_inventory_id' => $this->inventory->id,
                'quantity' => 1000,
            ])
            ->assertStatus(422);
    }

    public function test_fifo_feed_deduction_across_two_inventories(): void
    {
        $this->inventory->delete();

        $feedType = PoultryFeedType::where('farm_id', $this->farm->id)->first();

        $batch1 = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $feedType->id,
            'quantity' => 30,
            'unit_cost' => 2.5,
            'status' => 'available',
            'batch_number' => 'BATCH-FIFO-1',
            'created_at' => now()->subHours(2),
        ]);

        $batch2 = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $feedType->id,
            'quantity' => 50,
            'unit_cost' => 3.0,
            'status' => 'available',
            'batch_number' => 'BATCH-FIFO-2',
            'created_at' => now()->subHour(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feed-usages", [
                'poultry_feed_type_id' => $feedType->id,
                'flock_id' => $this->flock->id,
                'quantity' => 40,
                'usage_date' => now()->toDateString(),
            ]);

        $response->assertStatus(201);

        $this->assertEquals(0.0, (float) $batch1->fresh()->quantity);
        $this->assertEquals('closed', $batch1->fresh()->status);

        $this->assertEquals(40.0, (float) $batch2->fresh()->quantity);
        $this->assertEquals('available', $batch2->fresh()->status);

        // Assert neither batch is negative
        $this->assertGreaterThanOrEqual(0, (float) $batch1->fresh()->quantity);
        $this->assertGreaterThanOrEqual(0, (float) $batch2->fresh()->quantity);

        // Assert two usage records were created
        $this->assertDatabaseHas('poultry_feed_usages', [
            'poultry_feed_inventory_id' => $batch1->id,
            'quantity' => 30,
            'flock_id' => $this->flock->id,
            'unit_cost' => 2.5,
        ]);

        $this->assertDatabaseHas('poultry_feed_usages', [
            'poultry_feed_inventory_id' => $batch2->id,
            'quantity' => 10,
            'flock_id' => $this->flock->id,
            'unit_cost' => 3.0,
        ]);

        // Assert expenditures recorded accurately per batch cost
        $this->assertDatabaseHas('flock_expenditures', [
            'flock_id' => $this->flock->id,
            'amount' => 75.00, // 30 * 2.5
        ]);

        $this->assertDatabaseHas('flock_expenditures', [
            'flock_id' => $this->flock->id,
            'amount' => 30.00, // 10 * 3.0
        ]);
    }

    public function test_fifo_feed_deduction_single_inventory_sufficient_stock(): void
    {
        $this->inventory->update(['quantity' => 100]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feed-usages", [
                'poultry_feed_type_id' => $this->inventory->poultry_feed_type_id,
                'flock_id' => $this->flock->id,
                'quantity' => 40,
                'usage_date' => now()->toDateString(),
            ]);

        $response->assertStatus(201);
        $this->assertEquals(60.0, (float) $this->inventory->fresh()->quantity);
        $this->assertEquals('available', $this->inventory->fresh()->status);

        $this->assertDatabaseHas('poultry_feed_usages', [
            'poultry_feed_inventory_id' => $this->inventory->id,
            'quantity' => 40,
        ]);
    }

    public function test_fifo_feed_deduction_cascades_and_absorbs_overdraft_on_last_batch(): void
    {
        $this->inventory->delete();

        $feedType = PoultryFeedType::where('farm_id', $this->farm->id)->first();

        $batch1 = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $feedType->id,
            'quantity' => 30,
            'unit_cost' => 2.5,
            'status' => 'available',
            'batch_number' => 'BATCH-DEFICIT-1',
            'created_at' => now()->subHours(2),
        ]);

        $batch2 = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $feedType->id,
            'quantity' => 10,
            'unit_cost' => 3.0,
            'status' => 'available',
            'batch_number' => 'BATCH-DEFICIT-2',
            'created_at' => now()->subHour(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feed-usages", [
                'poultry_feed_type_id' => $feedType->id,
                'flock_id' => $this->flock->id,
                'quantity' => 45,
                'usage_date' => now()->toDateString(),
            ]);

        $response->assertStatus(201);

        $this->assertEquals(0.0, (float) $batch1->fresh()->quantity);
        $this->assertEquals('closed', $batch1->fresh()->status);

        $this->assertEquals(-5.0, (float) $batch2->fresh()->quantity);
        $this->assertEquals('depleted', $batch2->fresh()->status);

        $this->assertDatabaseHas('poultry_feed_usages', [
            'poultry_feed_inventory_id' => $batch1->id,
            'quantity' => 30,
        ]);

        $this->assertDatabaseHas('poultry_feed_usages', [
            'poultry_feed_inventory_id' => $batch2->id,
            'quantity' => 15,
        ]);
    }

    public function test_fifo_feed_deduction_honors_preferred_inventory_first(): void
    {
        $this->inventory->delete();

        $feedType = PoultryFeedType::where('farm_id', $this->farm->id)->first();

        $batch1 = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $feedType->id,
            'quantity' => 30,
            'unit_cost' => 2.5,
            'status' => 'available',
            'batch_number' => 'BATCH-PREF-1',
            'created_at' => now()->subHours(2),
        ]);

        $batch2 = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $feedType->id,
            'quantity' => 50,
            'unit_cost' => 3.0,
            'status' => 'available',
            'batch_number' => 'BATCH-PREF-2',
            'created_at' => now()->subHour(),
        ]);

        // Explicitly prefer batch2 (newer batch)
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feed-usages", [
                'poultry_feed_inventory_id' => $batch2->id,
                'poultry_feed_type_id' => $feedType->id,
                'flock_id' => $this->flock->id,
                'quantity' => 60,
                'usage_date' => now()->toDateString(),
            ]);

        $response->assertStatus(201);

        // Preferred batch 2 had 50kg, all deducted -> 0kg closed
        $this->assertEquals(0.0, (float) $batch2->fresh()->quantity);
        $this->assertEquals('closed', $batch2->fresh()->status);

        // Remainder (10kg) deducted from batch 1 -> 20kg remaining
        $this->assertEquals(20.0, (float) $batch1->fresh()->quantity);

        $this->assertDatabaseHas('poultry_feed_usages', [
            'poultry_feed_inventory_id' => $batch2->id,
            'quantity' => 50,
        ]);

        $this->assertDatabaseHas('poultry_feed_usages', [
            'poultry_feed_inventory_id' => $batch1->id,
            'quantity' => 10,
        ]);
    }

    public function test_daily_record_feed_usage_uses_fifo_deduction(): void
    {
        $this->inventory->delete();

        $feedType = PoultryFeedType::where('farm_id', $this->farm->id)->first();

        $batch1 = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $feedType->id,
            'quantity' => 30,
            'unit_cost' => 2.5,
            'status' => 'available',
            'batch_number' => 'BATCH-DAILY-1',
            'created_at' => now()->subHours(2),
        ]);

        $batch2 = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $feedType->id,
            'quantity' => 50,
            'unit_cost' => 3.0,
            'status' => 'available',
            'batch_number' => 'BATCH-DAILY-2',
            'created_at' => now()->subHour(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flock-daily-records", [
                'flock_id' => $this->flock->id,
                'date' => now()->toDateString(),
                'feed_consumed_kg' => 40,
            ]);

        $response->assertStatus(201);

        $this->assertEquals(0.0, (float) $batch1->fresh()->quantity);
        $this->assertEquals('closed', $batch1->fresh()->status);

        $this->assertEquals(40.0, (float) $batch2->fresh()->quantity);
        $this->assertEquals('available', $batch2->fresh()->status);

        $this->assertDatabaseHas('poultry_feed_usages', [
            'poultry_feed_inventory_id' => $batch1->id,
            'quantity' => 30,
        ]);

        $this->assertDatabaseHas('poultry_feed_usages', [
            'poultry_feed_inventory_id' => $batch2->id,
            'quantity' => 10,
        ]);
    }

    public function test_feed_usage_rejects_cross_poultry_type_feed_without_permission(): void
    {
        $layerType = PoultryType::factory()->create(['name' => 'Layer']);
        $layerFeedType = PoultryFeedType::create([
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_type_id' => $layerType->id,
            'name' => 'Layer Mash',
            'description' => 'For layers only',
        ]);

        $layerBatch = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $layerFeedType->id,
            'quantity' => 50,
            'unit_cost' => 4.0,
            'status' => 'available',
            'batch_number' => 'LAYER-BATCH-1',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feed-usages", [
                'poultry_feed_type_id' => $layerFeedType->id,
                'flock_id' => $this->flock->id,
                'quantity' => 20,
                'usage_date' => now()->toDateString(),
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['poultry_feed_type_id']);

        // Assert no inventory was deducted
        $this->assertEquals(50.0, (float) $layerBatch->fresh()->quantity);
        $this->assertDatabaseMissing('poultry_feed_usages', [
            'poultry_feed_type_id' => $layerFeedType->id,
            'flock_id' => $this->flock->id,
        ]);
    }

    public function test_feed_usage_permits_cross_poultry_type_feed_when_explicitly_permitted(): void
    {
        $layerType = PoultryType::factory()->create(['name' => 'Layer']);
        $layerFeedType = PoultryFeedType::create([
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_type_id' => $layerType->id,
            'name' => 'Layer Mash',
            'description' => 'For layers only',
        ]);

        $layerBatch = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $layerFeedType->id,
            'quantity' => 50,
            'unit_cost' => 4.0,
            'status' => 'available',
            'batch_number' => 'LAYER-BATCH-2',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feed-usages", [
                'poultry_feed_type_id' => $layerFeedType->id,
                'flock_id' => $this->flock->id,
                'quantity' => 20,
                'usage_date' => now()->toDateString(),
                'allow_poultry_type_mismatch' => true,
            ]);

        $response->assertStatus(201);

        $this->assertEquals(30.0, (float) $layerBatch->fresh()->quantity);
        $this->assertDatabaseHas('poultry_feed_usages', [
            'poultry_feed_type_id' => $layerFeedType->id,
            'flock_id' => $this->flock->id,
            'quantity' => 20,
        ]);
    }

    public function test_daily_record_rejects_cross_poultry_type_inventory_without_permission(): void
    {
        $layerType = PoultryType::factory()->create(['name' => 'Layer']);
        $layerFeedType = PoultryFeedType::create([
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_type_id' => $layerType->id,
            'name' => 'Layer Mash',
            'description' => 'For layers only',
        ]);

        $layerBatch = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $layerFeedType->id,
            'quantity' => 50,
            'unit_cost' => 4.0,
            'status' => 'available',
            'batch_number' => 'LAYER-DAILY-1',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flock-daily-records", [
                'flock_id' => $this->flock->id,
                'date' => now()->toDateString(),
                'feed_consumed_kg' => 15,
                'poultry_feed_inventory_id' => $layerBatch->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['poultry_feed_inventory_id']);

        $this->assertEquals(50.0, (float) $layerBatch->fresh()->quantity);
        $this->assertDatabaseMissing('poultry_feed_usages', [
            'poultry_feed_inventory_id' => $layerBatch->id,
            'flock_id' => $this->flock->id,
        ]);
    }

    public function test_daily_record_permits_cross_poultry_type_inventory_when_explicitly_permitted(): void
    {
        $layerType = PoultryType::factory()->create(['name' => 'Layer']);
        $layerFeedType = PoultryFeedType::create([
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_type_id' => $layerType->id,
            'name' => 'Layer Mash',
            'description' => 'For layers only',
        ]);

        $layerBatch = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $layerFeedType->id,
            'quantity' => 50,
            'unit_cost' => 4.0,
            'status' => 'available',
            'batch_number' => 'LAYER-DAILY-2',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flock-daily-records", [
                'flock_id' => $this->flock->id,
                'date' => now()->toDateString(),
                'feed_consumed_kg' => 15,
                'poultry_feed_inventory_id' => $layerBatch->id,
                'allow_poultry_type_mismatch' => true,
            ]);

        $response->assertStatus(201);

        $this->assertEquals(35.0, (float) $layerBatch->fresh()->quantity);
        $this->assertDatabaseHas('poultry_feed_usages', [
            'poultry_feed_inventory_id' => $layerBatch->id,
            'flock_id' => $this->flock->id,
            'quantity' => 15,
        ]);
    }

    public function test_deduct_fifo_service_enforces_poultry_type_matching(): void
    {
        $layerType = PoultryType::factory()->create(['name' => 'Layer']);
        $layerFeedType = PoultryFeedType::create([
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_type_id' => $layerType->id,
            'name' => 'Layer Pellets',
            'description' => 'Layer only',
        ]);

        PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $layerFeedType->id,
            'quantity' => 50,
            'unit_cost' => 3.5,
            'status' => 'available',
            'batch_number' => 'SERVICE-TEST-1',
        ]);

        // Attempting deduction without mismatch permission throws InvalidArgumentException
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Explicit permission is required to use feed of a different poultry type.");

        \App\Services\FeedUsageInventoryService::deductFifo(
            $this->farm->id,
            $layerFeedType->id,
            10,
            $this->flock->id,
            now()->toDateString(),
            $this->user->id,
            null,
            null,
            false // allowPoultryTypeMismatch = false
        );
    }
}
