<?php

namespace Tests\Feature;

use App\Models\BatchSchedule;
use App\Models\BatchScheduleItem;
use App\Models\Country;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockStage;
use App\Models\Permission;
use App\Models\PoultryHouse;
use App\Models\PoultryMedication;
use App\Models\PoultryType;
use App\Models\PoultryVaccine;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleItem;
use App\Models\User;
use App\Services\MedVacBatchScheduleItemGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RecurrentScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private string $token;
    private PoultryType $poultryType;
    private PoultryHouse $house;
    private FlockStage $flockStage;

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
            'view flocks', 'manage flocks', 'create schedule items', 'view schedule items',
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

        $this->poultryType = PoultryType::factory()->create();
        $this->flockStage = FlockStage::factory()->create([
            'poultry_type_id' => $this->poultryType->id,
            'from_age' => 0,
            'to_age' => 365,
        ]);
        $this->house = PoultryHouse::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_type_id' => $this->poultryType->id,
        ]);
    }

    public function test_recurring_schedule_item_requires_interval_days(): void
    {
        $schedule = Schedule::create([
            'schedule_type' => 'medication',
            'poultry_type_id' => $this->poultryType->id,
            'type' => 'user',
            'farm_id' => $this->farm->id,
            'name' => 'Med Schedule',
        ]);

        $medication = PoultryMedication::create([
            'name' => 'Antibiotic',
            'farm_id' => $this->farm->id,
            'type' => 'user',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/medication/schedule-items", [
                'schedule_id' => $schedule->id,
                'age_days' => 7,
                'is_recurring' => true,
                'poultry_medication_id' => $medication->id,
                'name' => 'Weekly dose',
                'dose' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['interval_days']);
    }

    public function test_generator_expands_recurring_item_until_expected_end_date(): void
    {
        $arrivalDate = Carbon::parse('2026-01-01');
        $endDate = Carbon::parse('2026-01-31');

        $flock = Flock::factory()->create([
            'farm_id' => $this->farm->id,
            'house_id' => $this->house->id,
            'poultry_type_id' => $this->poultryType->id,
            'flock_stage_id' => $this->flockStage->id,
            'arrival_date' => $arrivalDate->toDateString(),
            'arrival_age_days' => 0,
            'expected_end_date' => $endDate->toDateString(),
        ]);

        $schedule = Schedule::create([
            'schedule_type' => 'vaccination',
            'poultry_type_id' => $this->poultryType->id,
            'type' => 'user',
            'farm_id' => $this->farm->id,
            'name' => 'Recurring Vac',
        ]);

        $vaccine = PoultryVaccine::create([
            'name' => 'ND',
            'farm_id' => $this->farm->id,
            'type' => 'user',
        ]);

        $item = ScheduleItem::create([
            'schedule_id' => $schedule->id,
            'age_days' => 7,
            'is_recurring' => true,
            'interval_days' => 3,
            'poultry_vaccine_id' => $vaccine->id,
            'name' => 'ND Booster',
            'dose' => 1,
        ]);

        $batchSchedule = BatchSchedule::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $flock->id,
            'schedule_id' => $schedule->id,
        ]);

        $generator = app(MedVacBatchScheduleItemGenerator::class);
        $created = $generator->generateForBatchSchedule($batchSchedule);

        // Ages 7, 10, 13, 16, 19, 22, 25, 28 => 8 dates (age 31 falls after expected_end_date)
        $this->assertSame(8, $created);

        $dates = BatchScheduleItem::where('batch_schedule_id', $batchSchedule->id)
            ->orderBy('scheduled_date')
            ->pluck('scheduled_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();

        $this->assertSame([
            '2026-01-08',
            '2026-01-11',
            '2026-01-14',
            '2026-01-17',
            '2026-01-20',
            '2026-01-23',
            '2026-01-26',
            '2026-01-29',
        ], $dates);

        $this->assertSame(8, BatchScheduleItem::where('schedule_item_id', $item->id)->count());
    }

    public function test_generator_is_idempotent_and_preserves_completed_items(): void
    {
        $flock = Flock::factory()->create([
            'farm_id' => $this->farm->id,
            'house_id' => $this->house->id,
            'poultry_type_id' => $this->poultryType->id,
            'flock_stage_id' => $this->flockStage->id,
            'arrival_date' => '2026-01-01',
            'arrival_age_days' => 0,
            'expected_end_date' => '2026-01-20',
        ]);

        $schedule = Schedule::create([
            'schedule_type' => 'medication',
            'poultry_type_id' => $this->poultryType->id,
            'type' => 'user',
            'farm_id' => $this->farm->id,
            'name' => 'Med',
        ]);

        $medication = PoultryMedication::create([
            'name' => 'Med A',
            'farm_id' => $this->farm->id,
            'type' => 'user',
        ]);

        ScheduleItem::create([
            'schedule_id' => $schedule->id,
            'age_days' => 5,
            'is_recurring' => true,
            'interval_days' => 5,
            'poultry_medication_id' => $medication->id,
            'name' => 'Recurring med',
            'dose' => 1,
        ]);

        $batchSchedule = BatchSchedule::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $flock->id,
            'schedule_id' => $schedule->id,
        ]);

        $generator = app(MedVacBatchScheduleItemGenerator::class);
        $firstRun = $generator->generateForBatchSchedule($batchSchedule);
        $secondRun = $generator->generateForBatchSchedule($batchSchedule);

        $this->assertGreaterThan(0, $firstRun);
        $this->assertSame(0, $secondRun);

        $completed = BatchScheduleItem::where('batch_schedule_id', $batchSchedule->id)->first();
        $completed->update(['status' => 'completed']);

        $thirdRun = $generator->generateForBatchSchedule($batchSchedule);
        $this->assertSame(0, $thirdRun);
        $this->assertSame('completed', $completed->fresh()->status);
    }

    public function test_flock_create_generates_recurring_batch_items(): void
    {
        $schedule = Schedule::create([
            'schedule_type' => 'vaccination',
            'poultry_type_id' => $this->poultryType->id,
            'type' => 'user',
            'farm_id' => $this->farm->id,
            'name' => 'Vac',
        ]);

        $vaccine = PoultryVaccine::create([
            'name' => 'IB',
            'farm_id' => $this->farm->id,
            'type' => 'user',
        ]);

        ScheduleItem::create([
            'schedule_id' => $schedule->id,
            'age_days' => 7,
            'is_recurring' => true,
            'interval_days' => 7,
            'poultry_vaccine_id' => $vaccine->id,
            'name' => 'Weekly IB',
            'dose' => 1,
        ]);

        $arrival = Carbon::now()->subDays(3)->toDateString();
        $end = Carbon::now()->addDays(25)->toDateString();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flocks", [
                'farm_id' => $this->farm->id,
                'house_id' => $this->house->id,
                'poultry_type_id' => $this->poultryType->id,
                'flock_stage_id' => $this->flockStage->id,
                'name' => 'Test flock',
                'batch_number' => 'BATCH-REC-001',
                'breed' => 'Ross 308',
                'source' => 'Hatchery',
                'quantity' => 500,
                'arrival_date' => $arrival,
                'arrival_age_days' => 0,
                'expected_end_date' => $end,
                'vaccination_schedule_id' => $schedule->id,
            ]);

        $response->assertStatus(201);

        $flockId = $response->json('data.id');
        $batchSchedule = BatchSchedule::where('flock_id', $flockId)->first();

        $this->assertNotNull($batchSchedule);
        $this->assertGreaterThan(1, BatchScheduleItem::where('batch_schedule_id', $batchSchedule->id)->count());
    }
}
