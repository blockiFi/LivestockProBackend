<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Farm;
use App\Models\FeedingBatchSchedule;
use App\Models\FeedingBatchScheduleItem;
use App\Models\FeedingSchedule;
use App\Models\FeedingScheduleItem;
use App\Models\Flock;
use App\Models\FlockStage;
use App\Models\Permission;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryFeedType;
use App\Models\PoultryHouse;
use App\Models\PoultryType;
use App\Models\Role;
use App\Models\User;
use App\Services\FeedingScheduleRangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FeedingScheduleRangeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private string $token;
    private PoultryFeedType $feedType;
    private PoultryType $poultryType;
    private FeedingScheduleRangeService $rangeService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rangeService = app(FeedingScheduleRangeService::class);

        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;
        $country = Country::factory()->create();
        $this->farm = Farm::factory()->create([
            'created_by' => $this->user->id,
            'country_id' => $country->id,
        ]);

        $permissions = collect([
            'view schedules',
            'update schedules',
            'view feeding schedules',
            'create feeding schedules',
            'update feeding schedules',
            'delete feeding schedules',
            'view feeding schedule items',
            'create feeding schedule items',
            'update feeding schedule items',
            'delete feeding schedule items',
            'view feeding batch schedule items',
            'create feeding batch schedule items',
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
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->farm->id);
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
            'title' => 'Range Test',
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

    public function test_resolve_for_day_inside_range_and_boundaries(): void
    {
        $schedule = $this->makeSchedule([
            ['start_day' => 1, 'end_day' => 7, 'quantity' => 15],
            ['start_day' => 8, 'end_day' => 14, 'quantity' => 20],
            ['start_day' => 50, 'end_day' => null, 'quantity' => 50],
        ]);

        $this->assertEquals(15, (float) $this->rangeService->resolveForDay($schedule, 1)->quantity);
        $this->assertEquals(15, (float) $this->rangeService->resolveForDay($schedule, 7)->quantity);
        $this->assertEquals(20, (float) $this->rangeService->resolveForDay($schedule, 8)->quantity);
        $this->assertEquals(20, (float) $this->rangeService->resolveForDay($schedule, 14)->quantity);
        $this->assertNull($this->rangeService->resolveForDay($schedule, 15));
        $this->assertEquals(50, (float) $this->rangeService->resolveForDay($schedule, 50)->quantity);
        $this->assertEquals(50, (float) $this->rangeService->resolveForDay($schedule, 999)->quantity);
    }

    public function test_validate_rejects_overlaps_and_multiple_open_ended(): void
    {
        $overlap = $this->rangeService->validateRanges([
            ['start_day' => 1, 'end_day' => 10],
            ['start_day' => 8, 'end_day' => 14],
        ]);
        $this->assertNotEmpty($overlap['errors']);

        $twoOpen = $this->rangeService->validateRanges([
            ['start_day' => 1, 'end_day' => null],
            ['start_day' => 50, 'end_day' => null],
        ]);
        $this->assertNotEmpty($twoOpen['errors']);

        $openNotLast = $this->rangeService->validateRanges([
            ['start_day' => 1, 'end_day' => null],
            ['start_day' => 50, 'end_day' => 60],
        ]);
        $this->assertNotEmpty($openNotLast['errors']);
    }

    public function test_validate_allows_adjacent_ranges_and_warns_on_gaps(): void
    {
        $adjacent = $this->rangeService->validateRanges([
            ['start_day' => 1, 'end_day' => 7],
            ['start_day' => 8, 'end_day' => 14],
        ]);
        $this->assertEmpty($adjacent['errors']);

        $gap = $this->rangeService->validateRanges([
            ['start_day' => 1, 'end_day' => 7],
            ['start_day' => 15, 'end_day' => 21],
        ]);
        $this->assertEmpty($gap['errors']);
        $this->assertNotEmpty($gap['warnings']);
    }

    public function test_store_rejects_overlapping_ranges_via_api(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feeding/schedules", [
                'title' => 'Bad overlap',
                'type' => 'user',
                'poultry_type_id' => $this->poultryType->id,
                'items' => [
                    [
                        'feed_type_id' => $this->feedType->id,
                        'feeding_times' => [['time' => '08:00', 'percentage' => 100]],
                        'quantity' => 15,
                        'start_day' => 1,
                        'end_day' => 10,
                    ],
                    [
                        'feed_type_id' => $this->feedType->id,
                        'feeding_times' => [['time' => '08:00', 'percentage' => 100]],
                        'quantity' => 20,
                        'start_day' => 5,
                        'end_day' => 14,
                    ],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_store_accepts_adjacent_and_open_ended_ranges(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feeding/schedules", [
                'title' => 'Good ranges',
                'type' => 'user',
                'poultry_type_id' => $this->poultryType->id,
                'items' => [
                    [
                        'feed_type_id' => $this->feedType->id,
                        'feeding_times' => [['time' => '08:00', 'percentage' => 100]],
                        'quantity' => 15,
                        'start_day' => 1,
                        'end_day' => 7,
                    ],
                    [
                        'feed_type_id' => $this->feedType->id,
                        'feeding_times' => [['time' => '08:00', 'percentage' => 100]],
                        'quantity' => 50,
                        'start_day' => 8,
                        'end_day' => null,
                    ],
                ],
            ])
            ->assertStatus(201);

        $items = $response->json('data.items');
        $this->assertCount(2, $items);
        $this->assertEquals(1, $items[0]['start_day']);
        $this->assertEquals(7, $items[0]['end_day']);
        $this->assertNull($items[1]['end_day']);
        $this->assertTrue($items[1]['is_open_ended']);
    }

    public function test_split_produces_two_ranges(): void
    {
        $schedule = $this->makeSchedule([
            ['start_day' => 1, 'end_day' => 14, 'quantity' => 20],
        ]);
        $item = $schedule->items->first();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/feeding/schedule-items/{$item->id}/split", [
                'day' => 7,
                'quantity' => 30,
            ])
            ->assertStatus(200);

        $this->assertEquals(1, $response->json('data.original.start_day'));
        $this->assertEquals(6, $response->json('data.original.end_day'));
        $this->assertEquals(7, $response->json('data.created.start_day'));
        $this->assertEquals(14, $response->json('data.created.end_day'));
        $this->assertEquals(30, (float) $response->json('data.created.quantity'));
    }

    public function test_daily_records_on_days_in_same_range_share_schedule_item(): void
    {
        $flockStage = FlockStage::factory()->create(['poultry_type_id' => $this->poultryType->id]);
        $house = PoultryHouse::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_type_id' => $this->poultryType->id,
        ]);
        $arrival = now()->subDays(10)->toDateString();
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
        $rangeItem = $schedule->items->first();

        FeedingBatchSchedule::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $flock->id,
            'feeding_schedule_id' => $schedule->id,
            'status' => 'in_progress',
        ]);

        $day3 = now()->subDays(8)->toDateString(); // arrival + 2 = day 3
        $day5 = now()->subDays(6)->toDateString(); // arrival + 4 = day 5

        PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 500,
            'unit_cost' => 2.5,
            'status' => 'available',
            'batch_number' => 'BATCH-RANGE',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flock-daily-records", [
                'flock_id' => $flock->id,
                'date' => $day3,
                'feed_consumed_kg' => 4,
                'mortality' => 0,
            ])
            ->assertStatus(201);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flock-daily-records", [
                'flock_id' => $flock->id,
                'date' => $day5,
                'feed_consumed_kg' => 5,
                'mortality' => 0,
            ])
            ->assertStatus(201);

        $batch = FeedingBatchSchedule::where('flock_id', $flock->id)->first();
        $batchItems = FeedingBatchScheduleItem::where('feeding_batch_schedule_id', $batch->id)->get();
        $this->assertGreaterThanOrEqual(2, $batchItems->count());
        $this->assertTrue($batchItems->every(fn ($i) => (int) $i->feeding_schedule_item_id === (int) $rangeItem->id));
    }

    public function test_items_by_date_returns_planned_range_fields(): void
    {
        $flockStage = FlockStage::factory()->create(['poultry_type_id' => $this->poultryType->id]);
        $house = PoultryHouse::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_type_id' => $this->poultryType->id,
        ]);
        $arrival = now()->subDays(5)->toDateString();
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
            ['start_day' => 1, 'end_day' => 14, 'quantity' => 42],
        ]);

        FeedingBatchSchedule::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $flock->id,
            'feeding_schedule_id' => $schedule->id,
            'status' => 'scheduled',
        ]);

        $today = now()->toDateString(); // day 6

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/feeding/batch-schedules/flock/{$flock->id}/items-by-date?date={$today}")
            ->assertStatus(200)
            ->assertJsonPath('data.planned_quantity', '42.00')
            ->assertJsonPath('data.start_day', 1)
            ->assertJsonPath('data.end_day', 14)
            ->assertJsonPath('data.is_planned', true);
    }

    public function test_collapse_migration_merges_and_repoints_batch_items(): void
    {
        $schedule = FeedingSchedule::create([
            'title' => 'Legacy days',
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_type_id' => $this->poultryType->id,
            'start_date' => now()->toDateString(),
        ]);

        $times = json_encode([['time' => '08:00', 'percentage' => 100]]);
        $ids = [];
        for ($day = 1; $day <= 5; $day++) {
            $ids[] = DB::table('feeding_schedule_items')->insertGetId([
                'feeding_schedule_id' => $schedule->id,
                'feed_type_id' => $this->feedType->id,
                'feeding_times' => $times,
                'quantity' => 40.00,
                'feeding_day' => $day,
                'start_day' => $day,
                'end_day' => $day,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $flockStage = FlockStage::factory()->create(['poultry_type_id' => $this->poultryType->id]);
        $house = PoultryHouse::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_type_id' => $this->poultryType->id,
        ]);
        $flock = Flock::factory()->create([
            'farm_id' => $this->farm->id,
            'house_id' => $house->id,
            'poultry_type_id' => $this->poultryType->id,
            'flock_stage_id' => $flockStage->id,
            'quantity' => 50,
            'arrival_date' => now()->subDays(10)->toDateString(),
            'status' => 'active',
        ]);

        $batch = FeedingBatchSchedule::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $flock->id,
            'feeding_schedule_id' => $schedule->id,
            'status' => 'scheduled',
        ]);

        $batchItemId = DB::table('feeding_batch_schedule_items')->insertGetId([
            'feeding_batch_schedule_id' => $batch->id,
            'feeding_schedule_item_id' => $ids[2], // day 3
            'actual_feeding_time' => $times,
            'actual_quantity' => 40,
            'feeding_date' => now()->subDays(8)->toDateString(),
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Re-run collapse migration class directly.
        $migration = require database_path('migrations/2026_08_26_160001_collapse_feeding_schedule_items_to_ranges.php');
        $migration->up();

        $remaining = DB::table('feeding_schedule_items')
            ->where('feeding_schedule_id', $schedule->id)
            ->get();

        $this->assertCount(1, $remaining);
        $this->assertEquals(1, $remaining->first()->start_day);
        $this->assertEquals(5, $remaining->first()->end_day);

        $repointed = DB::table('feeding_batch_schedule_items')->where('id', $batchItemId)->first();
        $this->assertEquals($remaining->first()->id, $repointed->feeding_schedule_item_id);
    }

    public function test_timeline_includes_gaps(): void
    {
        $schedule = $this->makeSchedule([
            ['start_day' => 1, 'end_day' => 7],
            ['start_day' => 15, 'end_day' => 21],
        ]);

        $timeline = $this->rangeService->timeline($schedule);
        $types = array_column($timeline, 'type');
        $this->assertContains('gap', $types);
        $this->assertContains('range', $types);
    }

    public function test_feeding_schedule_update_does_not_resolve_med_vac_schedule_model(): void
    {
        $feedingSchedule = $this->makeSchedule([
            ['start_day' => 1, 'end_day' => 7],
        ]);

        $this->assertNull(\App\Models\Schedule::find($feedingSchedule->id));

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/farms/{$this->farm->id}/feeding/schedules/{$feedingSchedule->id}", [
                'title' => 'Updated feeding title',
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated feeding title');
    }
}
