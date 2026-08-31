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
use App\Models\PoultryFlockEggReport;
use App\Models\PoultryFlockWeightReport;
use App\Models\PoultryHouse;
use App\Models\PoultryMortalityReport;
use App\Models\PoultryFeedUsage;
use App\Models\FlockExpenditure;
use App\Models\PoultryType;
use App\Models\Role;
use App\Models\User;
use App\Services\FeedingDayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlockDailyRecordAutoCreateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private string $token;
    private Flock $flock;
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

        $manageFlocks = Permission::firstOrCreate(
            ['name' => 'manage flocks', 'guard_name' => 'api']
        );

        $ownerRole = Role::create([
            'name' => 'owner',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $ownerRole->givePermissionTo($manageFlocks);

        $this->farm->users()->attach($this->user->id);
        $this->user->assignRole($ownerRole);

        $poultryType = PoultryType::factory()->create(['name' => 'Layer']);
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
            'arrival_date' => now()->subDays(10)->toDateString(),
            'arrival_age_days' => 1,
            'status' => 'active',
        ]);

        $this->feedType = PoultryFeedType::create([
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_type_id' => $poultryType->id,
            'name' => 'Layer Starter ' . $this->farm->id,
            'description' => 'Test feed',
        ]);

        PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 500,
            'unit_cost' => 2.5,
            'status' => 'available',
            'batch_number' => 'BATCH-TEST',
        ]);
    }

    public function test_daily_record_auto_creates_child_records_from_frontend_fields(): void
    {
        $date = now()->toDateString();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flock-daily-records", [
                'flock_id' => $this->flock->id,
                'date' => $date,
                'mortality' => 5,
                'culls' => 1,
                'feed_consumed_kg' => 10,
                'water_consumed_liters' => 20,
                'avg_weight_grams' => 1500,
                'min_weight_grams' => 1400,
                'max_weight_grams' => 1600,
                'sample_size' => 25,
                'min_temperature' => 22,
                'max_temperature' => 26,
                'humidity' => 65,
                'light_hours' => 16,
                'eggs_collected' => 120,
                'eggs_broken' => 3,
                'notes' => 'Daily check',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('flock_daily_records', [
            'flock_id' => $this->flock->id,
            'farm_id' => $this->farm->id,
            'mortality_count' => 5,
            'culling_count' => 1,
            'feed_consumption_kg' => 10,
            'average_weight_kg' => 1.5,
            'egg_production_count' => 120,
            'mortality' => 5,
            'culls' => 1,
            'feed_consumed_kg' => 10,
            'water_consumed_liters' => 20,
            'avg_weight_grams' => 1500,
            'eggs_collected' => 120,
        ]);

        $response->assertJsonPath('data.mortality', 5);
        $response->assertJsonPath('data.feed_consumed_kg', 10);
        $response->assertJsonPath('data.water_consumed_liters', 20);
        $response->assertJsonPath('data.avg_weight_grams', 1500);
        $response->assertJsonPath('data.min_weight_grams', 1400);
        $response->assertJsonPath('data.max_weight_grams', 1600);
        $response->assertJsonPath('data.sample_size', 25);

        $this->assertDatabaseHas('poultry_mortality_reports', [
            'flock_id' => $this->flock->id,
            'farm_id' => $this->farm->id,
            'mortality_count' => 5,
        ]);

        $this->assertDatabaseHas('poultry_flock_weight_reports', [
            'flock_id' => $this->flock->id,
            'farm_id' => $this->farm->id,
            'average_weight' => 1.5,
            'min_weight' => 1.4,
            'max_weight' => 1.6,
            'number_of_birds' => 94,
            'sample_size' => 25,
        ]);

        $this->assertDatabaseHas('poultry_flock_egg_reports', [
            'flock_id' => $this->flock->id,
            'farm_id' => $this->farm->id,
            'eggs_collected' => 120,
        ]);

        $this->assertDatabaseHas('poultry_feed_usages', [
            'flock_id' => $this->flock->id,
            'farm_id' => $this->farm->id,
            'quantity' => 10,
        ]);

        $this->assertEquals(1, PoultryMortalityReport::where('flock_id', $this->flock->id)->count());
        $this->assertEquals(1, PoultryFlockWeightReport::where('flock_id', $this->flock->id)->count());
        $this->assertEquals(1, PoultryFlockEggReport::where('flock_id', $this->flock->id)->count());
        $this->assertEquals(1, PoultryFeedUsage::where('flock_id', $this->flock->id)->count());
    }

    public function test_cannot_create_duplicate_daily_record_for_same_date(): void
    {
        $date = now()->toDateString();

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flock-daily-records", [
                'flock_id' => $this->flock->id,
                'date' => $date,
                'mortality' => 2,
                'feed_consumed_kg' => 5,
            ])
            ->assertStatus(201);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flock-daily-records", [
                'flock_id' => $this->flock->id,
                'date' => $date,
                'mortality' => 3,
                'feed_consumed_kg' => 6,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date']);

        $this->assertEquals(1, FlockDailyRecord::where('flock_id', $this->flock->id)->whereDate('date', $date)->count());
    }

    public function test_sequential_daily_records_reflect_mortality_in_weight_report_bird_counts(): void
    {
        $dates = [
            now()->subDays(2)->toDateString(),
            now()->subDays(1)->toDateString(),
            now()->toDateString(),
        ];

        foreach ([2, 1, 1] as $index => $mortality) {
            $this->withHeader('Authorization', 'Bearer ' . $this->token)
                ->postJson("/api/farms/{$this->farm->id}/flock-daily-records", [
                    'flock_id' => $this->flock->id,
                    'date' => $dates[$index],
                    'mortality' => $mortality,
                    'avg_weight_grams' => 1500,
                ])
                ->assertStatus(201);
        }

        $reports = PoultryFlockWeightReport::where('flock_id', $this->flock->id)
            ->orderBy('report_date')
            ->get();

        $this->assertCount(3, $reports);
        $this->assertEquals(98, $reports[0]->number_of_birds);
        $this->assertEquals(97, $reports[1]->number_of_birds);
        $this->assertEquals(96, $reports[2]->number_of_birds);
    }

    public function test_daily_record_does_not_duplicate_feed_usage_on_resubmit(): void
    {
        $date = now()->toDateString();
        $payload = [
            'flock_id' => $this->flock->id,
            'date' => $date,
            'feed_consumed_kg' => 8,
            'mortality' => 2,
        ];

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flock-daily-records", $payload)
            ->assertStatus(201);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flock-daily-records", array_merge($payload, [
                'date' => now()->addDay()->toDateString(),
            ]))
            ->assertStatus(201);

        $this->assertEquals(2, PoultryFeedUsage::where('flock_id', $this->flock->id)->count());
    }

    public function test_daily_record_can_be_updated_with_frontend_fields(): void
    {
        $date = now()->toDateString();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flock-daily-records", [
                'flock_id' => $this->flock->id,
                'date' => $date,
                'mortality' => 3,
                'feed_consumed_kg' => 6,
                'notes' => 'Initial entry',
            ])
            ->assertStatus(201);

        $recordId = $createResponse->json('data.id');

        $updateResponse = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/farms/{$this->farm->id}/flock-daily-records/{$recordId}", [
                'date' => $date,
                'mortality' => 8,
                'feed_consumed_kg' => 12,
                'water_consumed_liters' => 25,
                'notes' => 'Updated entry',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('flock_daily_records', [
            'id' => $recordId,
            'mortality_count' => 8,
            'feed_consumption_kg' => 12,
            'water_consumption_liters' => 25,
            'mortality' => 8,
            'feed_consumed_kg' => 12,
            'notes' => 'Updated entry',
        ]);

        $updateResponse->assertJsonPath('data.mortality', 8);
        $updateResponse->assertJsonPath('data.feed_consumed_kg', 12);
    }

    public function test_daily_record_on_arrival_date_uses_feeding_day_one_and_entered_quantity(): void
    {
        $arrivalDate = now()->toDateString();
        $this->flock->update([
            'arrival_date' => $arrivalDate,
            'arrival_age_days' => 1,
            'quantity' => 100,
            'expected_end_date' => null,
        ]);

        $feedingSchedule = FeedingSchedule::create([
            'title' => 'Starter Program',
            'description' => 'Test feeding schedule',
            'start_date' => $arrivalDate,
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_type_id' => $this->flock->poultry_type_id,
        ]);

        $dayOneItem = FeedingScheduleItem::create([
            'feeding_schedule_id' => $feedingSchedule->id,
            'feed_type_id' => $this->feedType->id,
            'feeding_times' => [['time' => '08:00', 'percentage' => 100]],
            'quantity' => 50,
            'start_day' => 1,
            'end_day' => 7,
            'feeding_day' => 1,
        ]);

        FeedingScheduleItem::create([
            'feeding_schedule_id' => $feedingSchedule->id,
            'feed_type_id' => $this->feedType->id,
            'feeding_times' => [['time' => '08:00', 'percentage' => 100]],
            'quantity' => 55,
            'start_day' => 8,
            'end_day' => 14,
            'feeding_day' => 8,
        ]);

        FeedingBatchSchedule::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'feeding_schedule_id' => $feedingSchedule->id,
            'status' => 'in_progress',
        ]);

        $freshFlock = $this->flock->fresh();
        $this->assertEquals(1, FeedingDayService::feedingDayForDate($freshFlock, $arrivalDate));

        $batchSchedule = FeedingBatchSchedule::with('schedule.items')
            ->where('flock_id', $this->flock->id)
            ->first();
        $this->assertNotNull($batchSchedule);
        $this->assertNotNull(
            $batchSchedule->schedule->items->first(fn ($i) => $i->coversDay(1))
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flock-daily-records", [
                'flock_id' => $this->flock->id,
                'date' => $arrivalDate,
                'feed_consumed_kg' => 12,
                'mortality' => 0,
            ])
            ->assertStatus(201);

        if (FeedingBatchScheduleItem::count() === 0) {
            $this->fail(
                'No batch item created. Response: '
                . json_encode($response->json())
                . ' BatchSchedules: '
                . FeedingBatchSchedule::count()
            );
        }

        $batchItem = FeedingBatchScheduleItem::where('feeding_schedule_item_id', $dayOneItem->id)
            ->where('feeding_date', $arrivalDate)
            ->first();

        $this->assertNotNull($batchItem, 'Batch item should be linked to feeding day 1, not day 2');
        $this->assertEquals('completed', $batchItem->status);
        $this->assertEquals(120.0, (float) $batchItem->actual_quantity);
        $this->assertEquals(12.0, (float) $batchItem->actual_total_kg);

        $this->assertDatabaseHas('poultry_feed_usages', [
            'flock_id' => $this->flock->id,
            'quantity' => 12,
        ]);

        $recordId = $response->json('data.id');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/farms/{$this->farm->id}/flock-daily-records/{$recordId}", [
                'date' => $arrivalDate,
                'feed_consumed_kg' => 15,
                'mortality' => 0,
            ])
            ->assertStatus(200);

        $batchItem->refresh();
        $this->assertEquals(150.0, (float) $batchItem->actual_quantity);
        $this->assertEquals(15.0, (float) $batchItem->actual_total_kg);

        $this->assertDatabaseHas('poultry_feed_usages', [
            'flock_id' => $this->flock->id,
            'quantity' => 15,
        ]);

        $this->assertEquals(
            1,
            FeedingBatchScheduleItem::whereDate('feeding_date', $arrivalDate)->count()
        );
    }

    public function test_daily_record_stores_entered_feed_total_kg_when_same_day_mortality_recorded(): void
    {
        $arrivalDate = now()->subDays(5)->toDateString();
        $recordDate = now()->toDateString();

        $this->flock->update([
            'arrival_date' => $arrivalDate,
            'arrival_age_days' => 1,
            'quantity' => 300,
            'expected_end_date' => null,
        ]);

        $feedingSchedule = FeedingSchedule::create([
            'title' => 'Grower Program',
            'description' => 'Test feeding schedule',
            'start_date' => $arrivalDate,
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_type_id' => $this->flock->poultry_type_id,
        ]);

        $feedingDay = FeedingDayService::feedingDayForDate($this->flock->fresh(), $recordDate);

        $scheduleItem = FeedingScheduleItem::create([
            'feeding_schedule_id' => $feedingSchedule->id,
            'feed_type_id' => $this->feedType->id,
            'feeding_times' => [['time' => '08:00', 'percentage' => 100]],
            'quantity' => 15,
            'start_day' => $feedingDay,
            'end_day' => $feedingDay + 6,
            'feeding_day' => $feedingDay,
        ]);

        FeedingBatchSchedule::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'feeding_schedule_id' => $feedingSchedule->id,
            'status' => 'in_progress',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flock-daily-records", [
                'flock_id' => $this->flock->id,
                'date' => $recordDate,
                'feed_consumed_kg' => 4.5,
                'mortality' => 2,
            ])
            ->assertStatus(201);

        $batchItem = FeedingBatchScheduleItem::where('feeding_schedule_item_id', $scheduleItem->id)
            ->where('feeding_date', $recordDate)
            ->first();

        $this->assertNotNull($batchItem);
        $this->assertEquals(4.5, (float) $batchItem->actual_total_kg);
        $this->assertEquals(15.0, (float) $batchItem->actual_quantity);

        $displayTotalGrams = FeedingDayService::actualTotalGrams(
            (float) $batchItem->actual_total_kg,
            (float) $batchItem->actual_quantity,
            $this->flock->fresh()->actual_quantity
        );
        $this->assertEquals(4500.0, $displayTotalGrams);
    }

    public function test_updating_daily_record_feed_adjusts_inventory(): void
    {
        $date = now()->toDateString();
        $inventory = PoultryFeedInventory::where('farm_id', $this->farm->id)->first();
        $initialQty = (float) $inventory->quantity;

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flock-daily-records", [
                'flock_id' => $this->flock->id,
                'date' => $date,
                'feed_consumed_kg' => 10,
                'mortality' => 0,
            ])
            ->assertStatus(201);

        $this->assertEquals($initialQty - 10, (float) $inventory->fresh()->quantity);

        $recordId = $createResponse->json('data.id');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/farms/{$this->farm->id}/flock-daily-records/{$recordId}", [
                'date' => $date,
                'feed_consumed_kg' => 15,
                'mortality' => 0,
            ])
            ->assertStatus(200);

        $this->assertEquals($initialQty - 15, (float) $inventory->fresh()->quantity);
        $this->assertDatabaseHas('poultry_feed_usages', [
            'flock_id' => $this->flock->id,
            'quantity' => 15,
        ]);
    }

    public function test_daily_record_can_be_deleted(): void
    {
        $date = now()->toDateString();
        $inventory = PoultryFeedInventory::where('farm_id', $this->farm->id)->first();
        $initialQty = (float) $inventory->quantity;

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flock-daily-records", [
                'flock_id' => $this->flock->id,
                'date' => $date,
                'mortality' => 2,
                'feed_consumed_kg' => 5,
                'avg_weight_grams' => 1500,
                'eggs_collected' => 120,
            ])
            ->assertStatus(201);

        $recordId = $createResponse->json('data.id');

        $this->assertDatabaseHas('poultry_mortality_reports', [
            'flock_id' => $this->flock->id,
            'mortality_count' => 2,
            'notes' => 'Auto-created from daily record entry.',
        ]);
        $this->assertDatabaseHas('poultry_feed_usages', [
            'flock_id' => $this->flock->id,
            'quantity' => 5,
        ]);
        $this->assertDatabaseHas('poultry_flock_weight_reports', [
            'flock_id' => $this->flock->id,
            'average_weight' => 1.5,
        ]);
        $this->assertDatabaseHas('poultry_flock_egg_reports', [
            'flock_id' => $this->flock->id,
            'eggs_collected' => 120,
        ]);
        $this->assertEquals($initialQty - 5, (float) $inventory->fresh()->quantity);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson("/api/farms/{$this->farm->id}/flock-daily-records/{$recordId}")
            ->assertStatus(200);

        $this->assertSoftDeleted('flock_daily_records', ['id' => $recordId]);
        $this->assertNull(FlockDailyRecord::find($recordId));

        $this->assertEquals(0, PoultryMortalityReport::where('flock_id', $this->flock->id)->whereDate('date', $date)->count());
        $this->assertEquals(0, PoultryFeedUsage::where('flock_id', $this->flock->id)->whereDate('usage_date', $date)->count());
        $this->assertEquals(0, PoultryFlockWeightReport::where('flock_id', $this->flock->id)->whereDate('report_date', $date)->count());
        $this->assertEquals(0, PoultryFlockEggReport::where('flock_id', $this->flock->id)->whereDate('date', $date)->count());
        $this->assertEquals($initialQty, (float) $inventory->fresh()->quantity);
    }

    public function test_daily_record_delete_preserves_manual_mortality_reports(): void
    {
        $date = now()->toDateString();

        PoultryMortalityReport::create([
            'flock_id' => $this->flock->id,
            'farm_id' => $this->farm->id,
            'poultry_type_id' => $this->flock->poultry_type_id,
            'date' => $date,
            'mortality_count' => 3,
            'bird_count' => 100,
            'mortality_percentage' => 3,
            'notes' => 'Manual mortality entry',
            'recorded_by' => $this->user->id,
        ]);

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flock-daily-records", [
                'flock_id' => $this->flock->id,
                'date' => $date,
                'mortality' => 5,
                'feed_consumed_kg' => 4,
            ])
            ->assertStatus(201);

        $this->assertEquals(2, PoultryMortalityReport::where('flock_id', $this->flock->id)->whereDate('date', $date)->count());

        $recordId = $createResponse->json('data.id');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson("/api/farms/{$this->farm->id}/flock-daily-records/{$recordId}")
            ->assertStatus(200);

        $this->assertEquals(1, PoultryMortalityReport::where('flock_id', $this->flock->id)->whereDate('date', $date)->count());
        $this->assertDatabaseHas('poultry_mortality_reports', [
            'flock_id' => $this->flock->id,
            'mortality_count' => 3,
            'notes' => 'Manual mortality entry',
        ]);
    }

    public function test_daily_record_records_full_feed_usage_when_inventory_is_insufficient(): void
    {
        $inventory = PoultryFeedInventory::where('farm_id', $this->farm->id)->first();
        $inventory->update(['quantity' => 43]);

        $date = '2025-10-08';

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flock-daily-records", [
                'flock_id' => $this->flock->id,
                'date' => $date,
                'feed_consumed_kg' => 56.4,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('poultry_feed_usages', [
            'flock_id' => $this->flock->id,
            'farm_id' => $this->farm->id,
            'quantity' => 56.4,
        ]);
        $this->assertEqualsWithDelta(-13.4, (float) $inventory->fresh()->quantity, 0.01);
    }

    public function test_daily_record_with_inventory_id_deducts_chosen_batch_not_oldest(): void
    {
        $oldest = PoultryFeedInventory::where('farm_id', $this->farm->id)->first();
        $oldest->update([
            'quantity' => 200,
            'created_at' => now()->subDays(5),
        ]);

        $preferred = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 300,
            'unit_cost' => 3.0,
            'status' => 'available',
            'batch_number' => 'BATCH-PREFERRED',
            'created_at' => now()->subDay(),
        ]);

        $date = now()->toDateString();

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flock-daily-records", [
                'flock_id' => $this->flock->id,
                'date' => $date,
                'feed_consumed_kg' => 25,
                'poultry_feed_inventory_id' => $preferred->id,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('poultry_feed_usages', [
            'flock_id' => $this->flock->id,
            'poultry_feed_inventory_id' => $preferred->id,
            'quantity' => 25,
        ]);

        $this->assertEqualsWithDelta(275, (float) $preferred->fresh()->quantity, 0.01);
        $this->assertEqualsWithDelta(200, (float) $oldest->fresh()->quantity, 0.01);
    }

    public function test_daily_record_without_inventory_id_uses_fifo(): void
    {
        $oldest = PoultryFeedInventory::where('farm_id', $this->farm->id)->first();
        $oldest->update([
            'quantity' => 200,
            'created_at' => now()->subDays(5),
        ]);

        PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 300,
            'unit_cost' => 3.0,
            'status' => 'available',
            'batch_number' => 'BATCH-NEWER',
            'created_at' => now()->subDay(),
        ]);

        $date = now()->toDateString();

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flock-daily-records", [
                'flock_id' => $this->flock->id,
                'date' => $date,
                'feed_consumed_kg' => 15,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('poultry_feed_usages', [
            'flock_id' => $this->flock->id,
            'poultry_feed_inventory_id' => $oldest->id,
            'quantity' => 15,
        ]);

        $this->assertEqualsWithDelta(185, (float) $oldest->fresh()->quantity, 0.01);
    }

    public function test_updating_daily_record_can_switch_feed_inventory_batch(): void
    {
        $first = PoultryFeedInventory::where('farm_id', $this->farm->id)->first();
        $first->update(['quantity' => 200]);

        $second = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 200,
            'unit_cost' => 4.0,
            'status' => 'available',
            'batch_number' => 'BATCH-SECOND',
        ]);

        $date = now()->toDateString();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flock-daily-records", [
                'flock_id' => $this->flock->id,
                'date' => $date,
                'feed_consumed_kg' => 20,
                'poultry_feed_inventory_id' => $first->id,
            ])
            ->assertStatus(201);

        $recordId = $createResponse->json('data.id');
        $this->assertEqualsWithDelta(180, (float) $first->fresh()->quantity, 0.01);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/farms/{$this->farm->id}/flock-daily-records/{$recordId}", [
                'date' => $date,
                'feed_consumed_kg' => 20,
                'poultry_feed_inventory_id' => $second->id,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('poultry_feed_usages', [
            'flock_id' => $this->flock->id,
            'poultry_feed_inventory_id' => $second->id,
            'quantity' => 20,
        ]);

        $this->assertEqualsWithDelta(200, (float) $first->fresh()->quantity, 0.01);
        $this->assertEqualsWithDelta(180, (float) $second->fresh()->quantity, 0.01);
    }

    public function test_daily_record_creates_and_updates_feed_expenditure(): void
    {
        $date = now()->toDateString();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flock-daily-records", [
                'flock_id' => $this->flock->id,
                'date' => $date,
                'feed_consumed_kg' => 10,
            ])
            ->assertStatus(201);

        $usage = PoultryFeedUsage::where('flock_id', $this->flock->id)
            ->whereDate('usage_date', $date)
            ->first();
        $this->assertNotNull($usage);

        $this->assertDatabaseHas('flock_expenditures', [
            'flock_id' => $this->flock->id,
            'farm_id' => $this->farm->id,
            'category' => 'feed',
            'source_type' => 'feed_usage',
            'source_id' => $usage->id,
            'amount' => 25.00, // 10kg * 2.5
        ]);

        $recordId = $createResponse->json('data.id');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/farms/{$this->farm->id}/flock-daily-records/{$recordId}", [
                'date' => $date,
                'feed_consumed_kg' => 16,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('flock_expenditures', [
            'source_type' => 'feed_usage',
            'source_id' => $usage->id,
            'amount' => 40.00, // 16kg * 2.5
        ]);
        $this->assertEquals(1, FlockExpenditure::where('source_type', 'feed_usage')->where('source_id', $usage->id)->count());
    }

    public function test_daily_record_update_creates_missing_feed_expenditure(): void
    {
        $date = now()->toDateString();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flock-daily-records", [
                'flock_id' => $this->flock->id,
                'date' => $date,
                'feed_consumed_kg' => 8,
            ])
            ->assertStatus(201);

        $usage = PoultryFeedUsage::where('flock_id', $this->flock->id)
            ->whereDate('usage_date', $date)
            ->firstOrFail();

        FlockExpenditure::where('source_type', 'feed_usage')->where('source_id', $usage->id)->forceDelete();
        $this->assertEquals(0, FlockExpenditure::where('source_type', 'feed_usage')->where('source_id', $usage->id)->count());

        $recordId = $createResponse->json('data.id');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/farms/{$this->farm->id}/flock-daily-records/{$recordId}", [
                'date' => $date,
                'feed_consumed_kg' => 8,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('flock_expenditures', [
            'flock_id' => $this->flock->id,
            'source_type' => 'feed_usage',
            'source_id' => $usage->id,
            'amount' => 20.00, // 8kg * 2.5
        ]);
    }
}
