<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Farm;
use App\Models\FeedingBatchSchedule;
use App\Models\FeedingBatchScheduleItem;
use App\Models\FeedingSchedule;
use App\Models\FeedingScheduleItem;
use App\Models\Flock;
use App\Models\FlockDailyRecord;
use App\Models\FlockStage;
use App\Models\Permission;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryFeedType;
use App\Models\PoultryFeedUsage;
use App\Models\PoultryHouse;
use App\Models\PoultryType;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedingMissedScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private string $token;
    private PoultryFeedType $feedType;
    private PoultryType $poultryType;

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
            'view schedules',
            'create schedules',
            'update schedules',
            'delete schedules',
            'manage schedules',
            'view feeding schedules',
            'create feeding schedules',
            'update feeding schedules',
            'delete feeding schedules',
            'view feeding schedule items',
            'create feeding schedule items',
            'update feeding schedule items',
            'delete feeding schedule items',
            'view feeding batch schedules',
            'create feeding batch schedules',
            'update feeding batch schedules',
            'delete feeding batch schedules',
            'view feeding batch schedule items',
            'create feeding batch schedule items',
            'update feeding batch schedule items',
            'delete feeding batch schedule items',
            'view batch schedules',
            'create batch schedules',
            'update batch schedules',
            'delete batch schedules',
            'manage flocks',
            'create feed usages',
            'view feed usages',
        ])->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']));

        $ownerRole = Role::create([
            'name' => 'owner',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $ownerRole->givePermissionTo($permissions);
        $this->farm->users()->attach($this->user->id);
        $this->user->assignRole($ownerRole);

        $this->poultryType = PoultryType::factory()->create(['name' => 'Broiler']);
        $this->feedType = PoultryFeedType::create([
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_type_id' => $this->poultryType->id,
            'name' => 'Starter',
            'description' => 'Test',
        ]);
    }

    private function makeSchedule(array $ranges): FeedingSchedule
    {
        $schedule = FeedingSchedule::create([
            'title' => 'Missed Test',
            'description' => 'Test',
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_type_id' => $this->poultryType->id,
            'start_date' => now()->toDateString(),
        ]);

        foreach ($ranges as $range) {
            FeedingScheduleItem::create([
                'feeding_schedule_id' => $schedule->id,
                'feed_type_id' => $this->feedType->id,
                'feeding_times' => [['time' => '08:00', 'percentage' => 100]],
                'quantity' => $range['quantity'] ?? 40,
                'start_day' => $range['start_day'],
                'end_day' => $range['end_day'] ?? null,
                'feeding_day' => $range['start_day'],
            ]);
        }

        return $schedule->fresh(['items']);
    }

    private function createFlockOnDay(int $placementDay): array
    {
        $flockStage = FlockStage::factory()->create(['poultry_type_id' => $this->poultryType->id]);
        $house = PoultryHouse::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_type_id' => $this->poultryType->id,
        ]);

        $arrival = Carbon::today()->subDays($placementDay - 1)->toDateString();

        $flock = Flock::factory()->create([
            'farm_id' => $this->farm->id,
            'house_id' => $house->id,
            'poultry_type_id' => $this->poultryType->id,
            'flock_stage_id' => $flockStage->id,
            'quantity' => 100,
            'arrival_date' => $arrival,
            'status' => 'active',
        ]);

        $schedule = $this->makeSchedule([
            ['start_day' => 1, 'end_day' => 14, 'quantity' => 40],
        ]);

        $batch = FeedingBatchSchedule::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $flock->id,
            'feeding_schedule_id' => $schedule->id,
            'status' => 'in_progress',
        ]);

        return [$flock, $batch->fresh(['schedule.items']), $schedule];
    }

    public function test_missed_days_preview_returns_unrecorded_past_days(): void
    {
        [$flock, $batch] = $this->createFlockOnDay(10);
        $arrival = Carbon::parse($flock->arrival_date);

        // Record days 3 and 5 only (9 missed: days 1,2,4,6,7,8,9)
        FeedingBatchScheduleItem::create([
            'feeding_batch_schedule_id' => $batch->id,
            'feeding_schedule_item_id' => $batch->schedule->items->first()->id,
            'feeding_date' => $arrival->copy()->addDays(2)->toDateString(),
            'actual_quantity' => 40,
            'status' => 'completed',
        ]);
        FeedingBatchScheduleItem::create([
            'feeding_batch_schedule_id' => $batch->id,
            'feeding_schedule_item_id' => $batch->schedule->items->first()->id,
            'feeding_date' => $arrival->copy()->addDays(4)->toDateString(),
            'actual_quantity' => 40,
            'status' => 'completed',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/feeding/batch-schedules/{$batch->id}/missed-days")
            ->assertStatus(200);

        $this->assertEquals(7, $response->json('data.count'));
        $this->assertCount(7, $response->json('data.missed_days'));
        $this->assertIsArray($response->json('data.inventory_requirements'));
    }

    public function test_implement_missed_creates_late_items_and_deducts_inventory(): void
    {
        [$flock, $batch] = $this->createFlockOnDay(10);

        $inventory = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 500,
            'unit_cost' => 2.5,
            'status' => 'available',
            'batch_number' => 'BATCH-MISSED',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feeding/batch-schedules/{$batch->id}/implement-missed")
            ->assertStatus(201);

        $this->assertEquals(9, $response->json('data.created_count'));
        $this->assertEquals(9, $response->json('data.daily_records_created'));
        $this->assertEquals(0, $response->json('data.daily_records_updated'));
        $this->assertEquals(9, FeedingBatchScheduleItem::where('feeding_batch_schedule_id', $batch->id)->count());
        $this->assertTrue(
            FeedingBatchScheduleItem::where('feeding_batch_schedule_id', $batch->id)
                ->where('status', 'late')
                ->count() === 9
        );

        $this->assertEquals(9, PoultryFeedUsage::where('flock_id', $flock->id)->count());
        $this->assertEquals(9, FlockDailyRecord::where('flock_id', $flock->id)->count());
        $this->assertLessThan(500, (float) $inventory->fresh()->quantity);
    }

    public function test_implement_missed_updates_existing_daily_record_feed(): void
    {
        [$flock, $batch] = $this->createFlockOnDay(5);
        $arrival = Carbon::parse($flock->arrival_date);

        PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 500,
            'unit_cost' => 2.5,
            'status' => 'available',
            'batch_number' => 'BATCH-UPDATE-DR',
        ]);

        $existingDate = $arrival->copy()->addDays(1)->toDateString();
        FlockDailyRecord::create([
            'flock_id' => $flock->id,
            'farm_id' => $this->farm->id,
            'date' => $existingDate,
            'age_days' => 1,
            'total_birds' => 100,
            'mortality_count' => 3,
            'mortality' => 3,
            'feed_consumption_kg' => 0,
            'feed_consumed_kg' => 0,
            'recorded_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feeding/batch-schedules/{$batch->id}/implement-missed")
            ->assertStatus(201);

        $this->assertEquals(4, $response->json('data.created_count'));
        $this->assertEquals(3, $response->json('data.daily_records_created'));
        $this->assertEquals(1, $response->json('data.daily_records_updated'));

        $updated = FlockDailyRecord::where('flock_id', $flock->id)
            ->whereDate('date', $existingDate)
            ->first();

        $this->assertNotNull($updated);
        $this->assertEquals(3, $updated->mortality_count);
        $this->assertEquals(4.0, (float) $updated->feed_consumption_kg);
        $this->assertEquals(4.0, (float) $updated->feed_consumed_kg);
    }

    public function test_implement_missed_is_idempotent(): void
    {
        [, $batch] = $this->createFlockOnDay(5);

        PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 500,
            'unit_cost' => 2.5,
            'status' => 'available',
            'batch_number' => 'BATCH-IDEM',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feeding/batch-schedules/{$batch->id}/implement-missed")
            ->assertStatus(201);

        $firstCount = FeedingBatchScheduleItem::where('feeding_batch_schedule_id', $batch->id)->count();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feeding/batch-schedules/{$batch->id}/implement-missed")
            ->assertStatus(200);

        $this->assertEquals(0, $response->json('data.created_count'));
        $this->assertEquals($firstCount, FeedingBatchScheduleItem::where('feeding_batch_schedule_id', $batch->id)->count());
    }

    public function test_implement_missed_denied_without_permission(): void
    {
        [, $batch] = $this->createFlockOnDay(5);

        $viewer = User::factory()->create();
        $viewerToken = $viewer->createToken('viewer')->plainTextToken;
        $viewRole = Role::create([
            'name' => 'viewer',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $viewRole->givePermissionTo(
            Permission::firstOrCreate(['name' => 'view schedules', 'guard_name' => 'api']),
            Permission::firstOrCreate(['name' => 'view feeding batch schedule items', 'guard_name' => 'api'])
        );
        $this->farm->users()->attach($viewer->id);
        $viewer->assignRole($viewRole);

        $this->withHeader('Authorization', 'Bearer ' . $viewerToken)
            ->postJson("/api/farms/{$this->farm->id}/feeding/batch-schedules/{$batch->id}/implement-missed")
            ->assertStatus(403);
    }

    public function test_implement_missed_requires_inventory_selection_when_only_depleted_stock_exists(): void
    {
        [, $batch] = $this->createFlockOnDay(5);

        PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 0,
            'unit_cost' => 2.5,
            'status' => 'depleted',
            'batch_number' => 'DEPLETED-ONLY',
            'created_by' => $this->user->id,
        ]);

        $preview = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/feeding/batch-schedules/{$batch->id}/missed-days")
            ->assertStatus(200);

        $this->assertTrue($preview->json('data.inventory_requirements.0.needs_selection'));

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feeding/batch-schedules/{$batch->id}/implement-missed")
            ->assertStatus(422)
            ->assertJsonPath('errors.inventory_by_feed_type.0', fn ($message) => str_contains($message, 'Select feed inventory'));
    }

    public function test_implement_missed_rejects_depleted_inventory_selection(): void
    {
        [, $batch] = $this->createFlockOnDay(5);

        $depleted = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 0,
            'unit_cost' => 2.5,
            'status' => 'depleted',
            'batch_number' => 'DEPLETED-PICK',
            'created_by' => $this->user->id,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feeding/batch-schedules/{$batch->id}/implement-missed", [
                'inventory_by_feed_type' => [
                    (string) $this->feedType->id => $depleted->id,
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.inventory_by_feed_type.0',
                fn ($message) => str_contains($message, 'fully depleted')
            );
    }

    public function test_implement_missed_requires_inventory_selection_when_no_stock(): void
    {
        [, $batch] = $this->createFlockOnDay(5);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feeding/batch-schedules/{$batch->id}/implement-missed")
            ->assertStatus(422)
            ->assertJsonPath('errors.inventory_by_feed_type.0', fn ($message) => str_contains($message, 'Select feed inventory'));
    }

    public function test_implement_missed_uses_selected_inventory_when_auto_stock_missing(): void
    {
        [$flock, $batch] = $this->createFlockOnDay(5);

        $alternateType = PoultryFeedType::create([
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_type_id' => $this->poultryType->id,
            'name' => 'Grower',
            'description' => 'Alternate feed',
        ]);

        $alternateInventory = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $alternateType->id,
            'quantity' => 200,
            'unit_cost' => 2.0,
            'status' => 'available',
            'batch_number' => 'ALT-BATCH',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feeding/batch-schedules/{$batch->id}/implement-missed", [
                'inventory_by_feed_type' => [
                    (string) $this->feedType->id => $alternateInventory->id,
                ],
            ])
            ->assertStatus(201);

        $this->assertEquals(4, $response->json('data.created_count'));
        $this->assertEquals(4, PoultryFeedUsage::where('flock_id', $flock->id)->count());
        $this->assertLessThan(200, (float) $alternateInventory->fresh()->quantity);
    }

    public function test_revert_missed_removes_late_backfills_and_restores_inventory(): void
    {
        [$flock, $batch] = $this->createFlockOnDay(5);

        $inventory = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 500,
            'unit_cost' => 2.5,
            'status' => 'available',
            'batch_number' => 'BATCH-REVERT',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feeding/batch-schedules/{$batch->id}/implement-missed")
            ->assertStatus(201);

        $this->assertEquals(4, FeedingBatchScheduleItem::where('feeding_batch_schedule_id', $batch->id)->count());
        $inventoryAfterImplement = (float) $inventory->fresh()->quantity;
        $this->assertLessThan(500, $inventoryAfterImplement);

        $preview = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/feeding/batch-schedules/{$batch->id}/revertible-days")
            ->assertStatus(200);

        $this->assertEquals(4, $preview->json('data.count'));

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feeding/batch-schedules/{$batch->id}/revert-missed")
            ->assertStatus(200);

        $this->assertEquals(4, $response->json('data.reverted_count'));
        $this->assertEquals(0, FeedingBatchScheduleItem::where('feeding_batch_schedule_id', $batch->id)->count());
        $this->assertEquals(0, PoultryFeedUsage::where('flock_id', $flock->id)->count());
        $this->assertEquals(500, (float) $inventory->fresh()->quantity);
    }

    public function test_revert_missed_denied_without_create_permission(): void
    {
        [, $batch] = $this->createFlockOnDay(5);

        PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 500,
            'unit_cost' => 2.5,
            'status' => 'available',
            'batch_number' => 'BATCH-REVERT-DENY',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feeding/batch-schedules/{$batch->id}/implement-missed")
            ->assertStatus(201);

        $viewer = User::factory()->create();
        $viewRole = Role::create([
            'name' => 'viewer-revert',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $viewRole->givePermissionTo(
            Permission::firstOrCreate(['name' => 'view schedules', 'guard_name' => 'api']),
            Permission::firstOrCreate(['name' => 'view feeding batch schedule items', 'guard_name' => 'api'])
        );
        $this->farm->users()->attach($viewer->id);
        $viewer->assignRole($viewRole);

        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->farm->id);
        $this->assertFalse($viewer->can('create schedules', 'api', $this->farm->id));

        $this->actingAs($viewer, 'sanctum')
            ->postJson("/api/farms/{$this->farm->id}/feeding/batch-schedules/{$batch->id}/revert-missed")
            ->assertStatus(403);
    }
}
