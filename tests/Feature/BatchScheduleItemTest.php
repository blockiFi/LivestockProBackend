<?php

namespace Tests\Feature;

use App\Models\AdministrationMethod;
use App\Models\BatchSchedule;
use App\Models\Country;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockStage;
use App\Models\Permission;
use App\Models\PoultryHouse;
use App\Models\PoultryType;
use App\Models\PoultryVaccine;
use App\Models\PoultryVaccineProduct;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchScheduleItemTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private BatchSchedule $batchSchedule;
    private ScheduleItem $scheduleItem;
    private PoultryVaccineProduct $vaccineProduct;
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

        $permissions = collect(['view flocks', 'update flocks'])
            ->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']));

        $ownerRole = Role::create([
            'name' => 'owner',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $ownerRole->givePermissionTo($permissions);

        $this->farm->users()->attach($this->user->id);
        $this->user->assignRole($ownerRole);

        $poultryType = PoultryType::factory()->create();
        $flockStage = FlockStage::factory()->create(['poultry_type_id' => $poultryType->id]);
        $house = PoultryHouse::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_type_id' => $poultryType->id,
        ]);

        $flock = Flock::factory()->create([
            'farm_id' => $this->farm->id,
            'house_id' => $house->id,
            'poultry_type_id' => $poultryType->id,
            'flock_stage_id' => $flockStage->id,
            'quantity' => 100,
            'status' => 'active',
        ]);

        $schedule = Schedule::create([
            'schedule_type' => 'vaccination',
            'poultry_type_id' => $poultryType->id,
            'type' => 'user',
            'farm_id' => $this->farm->id,
            'name' => 'Broiler Vaccination',
            'description' => 'Test schedule',
        ]);

        $vaccine = PoultryVaccine::create([
            'name' => 'Gumboro',
            'farm_id' => $this->farm->id,
            'type' => 'user',
        ]);

        $this->scheduleItem = ScheduleItem::create([
            'schedule_id' => $schedule->id,
            'age_days' => 14,
            'poultry_vaccine_id' => $vaccine->id,
            'name' => 'Gumboro',
            'dose' => 1,
        ]);

        $this->batchSchedule = BatchSchedule::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $flock->id,
            'schedule_id' => $schedule->id,
        ]);

        $adminMethod = AdministrationMethod::create([
            'name' => 'Drinking Water',
            'description' => 'Via drinking water',
        ]);

        $this->vaccineProduct = PoultryVaccineProduct::create([
            'name' => 'Gumboro Vaccine',
            'manufacturer' => 'Test Pharma',
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_vaccine_id' => $vaccine->id,
            'administration_method_id' => $adminMethod->id,
        ]);
    }

    public function test_can_implement_vaccination_batch_schedule_item(): void
    {
        $date = now()->toDateString();

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/vaccination/batch-schedule-items", [
                'batch_schedule_id' => $this->batchSchedule->id,
                'schedule_item_id' => $this->scheduleItem->id,
                'scheduled_date' => $date,
                'actual_date' => $date,
                'status' => 'completed',
                'poultry_vaccine_product_id' => $this->vaccineProduct->id,
                'quantity' => 100,
                'cost' => 5000,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.batch_schedule_id', $this->batchSchedule->id)
            ->assertJsonPath('data.schedule_item_id', $this->scheduleItem->id);

        $this->assertDatabaseHas('batch_schedule_items', [
            'batch_schedule_id' => $this->batchSchedule->id,
            'schedule_item_id' => $this->scheduleItem->id,
            'poultry_vaccine_product_id' => $this->vaccineProduct->id,
            'poultry_medication_id' => null,
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('flock_expenditures', [
            'farm_id' => $this->farm->id,
            'flock_id' => $this->batchSchedule->flock_id,
            'category' => 'vaccination',
            'amount' => 5000,
            'source_type' => 'batch_schedule_item',
        ]);
    }
}
