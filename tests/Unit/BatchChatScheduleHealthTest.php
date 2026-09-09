<?php

namespace Tests\Unit;

use App\Models\BatchSchedule;
use App\Models\BatchScheduleItem;
use App\Models\Country;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockStage;
use App\Models\PoultryType;
use App\Models\Schedule;
use App\Models\ScheduleItem;
use App\Models\User;
use App\Services\BatchChat\BatchChatContextService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchChatScheduleHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_health_exposes_future_vaccinations_and_due_scheduled_items(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09'));

        $user = User::factory()->create();
        $country = Country::factory()->create();
        $farm = Farm::factory()->create([
            'created_by' => $user->id,
            'country_id' => $country->id,
        ]);
        $poultryType = PoultryType::factory()->create();
        $stage = FlockStage::factory()->create(['poultry_type_id' => $poultryType->id]);
        $flock = Flock::factory()->create([
            'farm_id' => $farm->id,
            'poultry_type_id' => $poultryType->id,
            'flock_stage_id' => $stage->id,
            'arrival_date' => '2026-08-01',
            'arrival_age_days' => 1,
            'status' => 'active',
        ]);

        $schedule = Schedule::create([
            'farm_id' => $farm->id,
            'poultry_type_id' => $poultryType->id,
            'schedule_type' => 'vaccination',
            'name' => 'Layer Vax Program',
            'description' => 'Test',
            'type' => 'user',
        ]);

        $futureItem = ScheduleItem::create([
            'schedule_id' => $schedule->id,
            'name' => 'Newcastle',
            'age_days' => 49,
            'dose' => 1,
        ]);
        $dueItem = ScheduleItem::create([
            'schedule_id' => $schedule->id,
            'name' => 'IBD',
            'age_days' => 27,
            'dose' => 1,
        ]);

        $batch = BatchSchedule::create([
            'farm_id' => $farm->id,
            'flock_id' => $flock->id,
            'schedule_id' => $schedule->id,
            'status' => 'active',
        ]);

        BatchScheduleItem::create([
            'batch_schedule_id' => $batch->id,
            'schedule_item_id' => $futureItem->id,
            'status' => 'scheduled',
            'scheduled_date' => '2026-09-16',
        ]);
        BatchScheduleItem::create([
            'batch_schedule_id' => $batch->id,
            'schedule_item_id' => $dueItem->id,
            'status' => 'scheduled',
            'scheduled_date' => '2026-09-09',
        ]);

        /** @var BatchChatContextService $service */
        $service = $this->app->make(BatchChatContextService::class);
        $health = $service->scheduleHealth($flock);

        $this->assertSame(1, $health['vaccination_due_or_overdue']);
        $this->assertSame(1, $health['medication_vaccination_due_or_overdue']);
        $this->assertNotNull($health['next_vaccination']);
        $this->assertSame('IBD', $health['next_vaccination']['name']);
        $this->assertSame('2026-09-09', $health['next_vaccination']['scheduled_date']);
        $this->assertCount(2, $health['upcoming_vaccinations']);
        $this->assertSame('Newcastle', $health['upcoming_vaccinations'][1]['name']);
        $this->assertSame('2026-09-16', $health['upcoming_vaccinations'][1]['scheduled_date']);

        Carbon::setTestNow();
    }

    public function test_zero_due_does_not_hide_future_only_schedule(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09'));

        $user = User::factory()->create();
        $country = Country::factory()->create();
        $farm = Farm::factory()->create([
            'created_by' => $user->id,
            'country_id' => $country->id,
        ]);
        $poultryType = PoultryType::factory()->create();
        $stage = FlockStage::factory()->create(['poultry_type_id' => $poultryType->id]);
        $flock = Flock::factory()->create([
            'farm_id' => $farm->id,
            'poultry_type_id' => $poultryType->id,
            'flock_stage_id' => $stage->id,
            'arrival_date' => '2026-08-01',
            'status' => 'active',
        ]);

        $schedule = Schedule::create([
            'farm_id' => $farm->id,
            'poultry_type_id' => $poultryType->id,
            'schedule_type' => 'vaccination',
            'name' => 'Future Only',
            'type' => 'user',
        ]);
        $item = ScheduleItem::create([
            'schedule_id' => $schedule->id,
            'name' => 'Fowl Pox',
            'age_days' => 41,
            'dose' => 1,
        ]);
        $batch = BatchSchedule::create([
            'farm_id' => $farm->id,
            'flock_id' => $flock->id,
            'schedule_id' => $schedule->id,
            'status' => 'active',
        ]);
        BatchScheduleItem::create([
            'batch_schedule_id' => $batch->id,
            'schedule_item_id' => $item->id,
            'status' => 'scheduled',
            'scheduled_date' => '2026-09-20',
        ]);

        $health = $this->app->make(BatchChatContextService::class)->scheduleHealth($flock);

        $this->assertSame(0, $health['vaccination_due_or_overdue']);
        $this->assertSame(0, $health['medication_vaccination_pending_or_overdue']);
        $this->assertSame('Fowl Pox', $health['next_vaccination']['name']);
        $this->assertSame(11, $health['next_vaccination']['days_until']);

        Carbon::setTestNow();
    }
}
