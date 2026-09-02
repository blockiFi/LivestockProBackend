<?php

namespace Tests\Feature;

use App\Models\BatchSchedule;
use App\Models\Country;
use App\Models\Farm;
use App\Models\FarmTaskInstance;
use App\Models\FeedingBatchSchedule;
use App\Models\FeedingSchedule;
use App\Models\FeedingScheduleItem;
use App\Models\Flock;
use App\Models\FlockStage;
use App\Models\Permission;
use App\Models\PoultryFeedType;
use App\Models\PoultryHouse;
use App\Models\PoultryMortalityReport;
use App\Models\PoultryFlockEggReport;
use App\Models\PoultryType;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FlockActivityReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private Flock $flock;
    private Flock $otherFlock;
    private string $token;

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

        $viewFlocks = Permission::findOrCreate(
            'view flocks',
            'api',
            $this->farm->id
        );
        $ownerRole = Role::create([
            'name' => 'owner',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $ownerRole->givePermissionTo([$viewFlocks]);

        $this->farm->users()->attach($this->user->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->farm->id);
        $this->user->assignRole($ownerRole);

        $poultryType = PoultryType::factory()->create(['name' => 'Layers']);
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
            'batch_number' => 'B001',
            'arrival_date' => Carbon::today()->subDays(60)->toDateString(),
            'arrival_age_days' => 1,
            'quantity' => 500,
            'status' => 'active',
        ]);

        $this->otherFlock = Flock::factory()->create([
            'farm_id' => $this->farm->id,
            'house_id' => $house->id,
            'poultry_type_id' => $poultryType->id,
            'flock_stage_id' => $flockStage->id,
            'quantity' => 200,
            'status' => 'active',
        ]);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson($this->activitiesUrl())
            ->assertStatus(401);
    }

    public function test_validates_date_range(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson($this->activitiesUrl([
                'start_date' => '2026-09-10',
                'end_date' => '2026-09-01',
            ]))
            ->assertStatus(422);
    }

    public function test_returns_mortality_and_task_activities_for_flock(): void
    {
        $today = Carbon::today()->toDateString();

        PoultryMortalityReport::create([
            'flock_id' => $this->flock->id,
            'farm_id' => $this->farm->id,
            'poultry_type_id' => $this->flock->poultry_type_id,
            'date' => $today,
            'mortality_count' => 4,
            'bird_count' => 500,
            'mortality_percentage' => 0.8,
            'recorded_by' => $this->user->id,
        ]);

        PoultryMortalityReport::create([
            'flock_id' => $this->otherFlock->id,
            'farm_id' => $this->farm->id,
            'poultry_type_id' => $this->otherFlock->poultry_type_id,
            'date' => $today,
            'mortality_count' => 99,
            'bird_count' => 200,
            'mortality_percentage' => 49.5,
            'recorded_by' => $this->user->id,
        ]);

        FarmTaskInstance::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'title' => 'Pen inspection',
            'section' => 'general',
            'priority' => 'medium',
            'scheduled_date' => $today,
            'status' => 'completed',
            'require_completion_confirmation' => false,
            'require_supervisor_approval' => false,
            'require_signature' => false,
            'awaiting_approval' => false,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson($this->activitiesUrl([
                'start_date' => $today,
                'end_date' => $today,
            ]))
            ->assertOk()
            ->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertSame('B001', $data['batch']['batch_number']);
        $this->assertSame(2, $data['summary']['total_activities']);
        $this->assertSame(4, $data['summary']['mortality_count']);

        $categories = collect($data['activities']['data'])->pluck('category')->all();
        $this->assertContains('mortality', $categories);
        $this->assertContains('task', $categories);
        $this->assertNotContains(99, collect($data['activities']['data'])->pluck('quantity')->all());
    }

    public function test_filters_by_activity_type_and_search(): void
    {
        $today = Carbon::today()->toDateString();

        PoultryMortalityReport::create([
            'flock_id' => $this->flock->id,
            'farm_id' => $this->farm->id,
            'poultry_type_id' => $this->flock->poultry_type_id,
            'date' => $today,
            'mortality_count' => 2,
            'bird_count' => 500,
            'mortality_percentage' => 0.4,
            'notes' => 'Heat stress',
            'recorded_by' => $this->user->id,
        ]);

        FarmTaskInstance::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'title' => 'Feed check',
            'section' => 'feeding',
            'priority' => 'medium',
            'scheduled_date' => $today,
            'status' => 'pending',
            'require_completion_confirmation' => false,
            'require_supervisor_approval' => false,
            'require_signature' => false,
            'awaiting_approval' => false,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson($this->activitiesUrl([
                'start_date' => $today,
                'end_date' => $today,
                'activity_type' => 'mortality',
                'search' => 'heat',
            ]))
            ->assertOk()
            ->assertJsonPath('data.summary.total_activities', 1)
            ->assertJsonPath('data.activities.data.0.category', 'mortality');
    }

    public function test_pagination_works(): void
    {
        $today = Carbon::today()->toDateString();

        for ($i = 0; $i < 3; $i++) {
            FarmTaskInstance::create([
                'farm_id' => $this->farm->id,
                'flock_id' => $this->flock->id,
                'title' => "Task {$i}",
                'section' => 'general',
                'priority' => 'medium',
                'scheduled_date' => $today,
                'status' => 'pending',
                'require_completion_confirmation' => false,
                'require_supervisor_approval' => false,
                'require_signature' => false,
                'awaiting_approval' => false,
            ]);
        }

        $this->assertDatabaseCount('farm_task_instances', 3);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson($this->activitiesUrl([
                'start_date' => $today,
                'end_date' => $today,
                'per_page' => 2,
                'page' => 1,
            ]))
            ->assertOk()
            ->assertJsonPath('data.activities.per_page', 2)
            ->assertJsonPath('data.activities.total', 3)
            ->assertJsonCount(2, 'data.activities.data');
    }

    public function test_includes_planned_med_vac_and_feeding_activities(): void
    {
        $today = Carbon::today();
        $futureDate = $today->copy()->addDays(4)->toDateString();
        $pastDate = $today->copy()->subDays(3)->toDateString();

        $this->flock->update([
            'arrival_date' => $today->copy()->subDays(60)->toDateString(),
            'arrival_age_days' => 1,
        ]);

        $vacSchedule = Schedule::create([
            'schedule_type' => 'vaccination',
            'poultry_type_id' => $this->flock->poultry_type_id,
            'type' => 'user',
            'farm_id' => $this->farm->id,
            'name' => 'Layer Vaccination',
            'description' => 'Test',
        ]);

        // Future: age 65 → offset 64 → today + 4
        ScheduleItem::create([
            'schedule_id' => $vacSchedule->id,
            'age_days' => 65,
            'name' => 'Future Vaccine',
            'dose' => 1,
        ]);

        // Past missed: age 58 → offset 57 → today - 3
        ScheduleItem::create([
            'schedule_id' => $vacSchedule->id,
            'age_days' => 58,
            'name' => 'Missed Vaccine',
            'dose' => 1,
        ]);

        BatchSchedule::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'schedule_id' => $vacSchedule->id,
        ]);

        $feedType = PoultryFeedType::create([
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_type_id' => $this->flock->poultry_type_id,
            'name' => 'Grower',
            'description' => 'Test',
        ]);

        $feedingSchedule = FeedingSchedule::create([
            'title' => 'Activity Report Feeding',
            'description' => 'Test',
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_type_id' => $this->flock->poultry_type_id,
            'start_date' => $today->toDateString(),
        ]);

        FeedingScheduleItem::create([
            'feeding_schedule_id' => $feedingSchedule->id,
            'feed_type_id' => $feedType->id,
            'feeding_times' => [['time' => '08:00', 'percentage' => 100]],
            'quantity' => 50,
            'start_day' => 1,
            'end_day' => 90,
            'feeding_day' => 1,
        ]);

        FeedingBatchSchedule::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'feeding_schedule_id' => $feedingSchedule->id,
            'status' => 'in_progress',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson($this->activitiesUrl([
                'start_date' => $pastDate,
                'end_date' => $futureDate,
                'per_page' => 100,
            ]))
            ->assertOk();

        $activities = collect($response->json('data.activities.data'));

        $futureVac = $activities->first(fn ($row) => str_contains($row['description'] ?? '', 'Future Vaccine'));
        $this->assertNotNull($futureVac);
        $this->assertSame('scheduled', $futureVac['status']);
        $this->assertSame('vaccination', $futureVac['category']);
        $this->assertSame($futureDate, $futureVac['date']);

        $missedVac = $activities->first(fn ($row) => str_contains($row['description'] ?? '', 'Missed Vaccine'));
        $this->assertNotNull($missedVac);
        $this->assertSame('missed', $missedVac['status']);

        $plannedFeeding = $activities->first(
            fn ($row) => $row['category'] === 'feeding'
                && str_contains($row['activity'] ?? '', 'planned')
                && $row['date'] === $today->toDateString()
        );
        $this->assertNotNull($plannedFeeding);
        $this->assertContains($plannedFeeding['status'], ['scheduled', 'missed']);
    }

    public function test_includes_egg_production_activities(): void
    {
        $today = Carbon::today()->toDateString();

        PoultryFlockEggReport::create([
            'flock_id' => $this->flock->id,
            'farm_id' => $this->farm->id,
            'date' => $today,
            'eggs_collected' => 320,
            'average_egg_weight' => 58.5,
            'production_percentage' => 64,
            'bird_count' => 500,
            'recorded_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson($this->activitiesUrl([
                'start_date' => $today,
                'end_date' => $today,
            ]))
            ->assertOk();

        $eggRow = collect($response->json('data.activities.data'))
            ->first(fn ($row) => $row['category'] === 'egg_production');

        $this->assertNotNull($eggRow);
        $this->assertSame(320, $eggRow['quantity']);
        $this->assertSame($today, $eggRow['date']);
    }

    private function activitiesUrl(array $query = []): string
    {
        $base = "/api/farms/{$this->farm->id}/flocks/{$this->flock->id}/activities";

        if (empty($query)) {
            $query = [
                'start_date' => Carbon::today()->subDays(7)->toDateString(),
                'end_date' => Carbon::today()->toDateString(),
            ];
        }

        return $base . '?' . http_build_query($query);
    }
}
