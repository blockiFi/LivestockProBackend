<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockStage;
use App\Models\Permission;
use App\Models\PoultryHouse;
use App\Models\PoultryMortalityReport;
use App\Models\PoultryType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlockMortalityReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private string $token;
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

        $manageFlocks = Permission::firstOrCreate(
            ['name' => 'manage flocks', 'guard_name' => 'api']
        );
        $deleteMortality = Permission::firstOrCreate(
            ['name' => 'delete flock mortality reports', 'guard_name' => 'api']
        );

        $ownerRole = Role::create([
            'name' => 'owner',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $ownerRole->givePermissionTo([$manageFlocks, $deleteMortality]);

        $this->farm->users()->attach($this->user->id);
        $this->user->assignRole($ownerRole);

        $poultryType = PoultryType::factory()->create(['name' => 'Broiler']);
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
    }

    public function test_mortality_report_can_be_deleted(): void
    {
        $report = PoultryMortalityReport::create([
            'flock_id' => $this->flock->id,
            'farm_id' => $this->farm->id,
            'poultry_type_id' => $this->flock->poultry_type_id,
            'date' => now()->toDateString(),
            'mortality_count' => 3,
            'bird_count' => 100,
            'mortality_percentage' => 3,
            'notes' => 'Test mortality',
            'recorded_by' => $this->user->id,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson("/api/farms/{$this->farm->id}/flock-mortality-reports/{$report->id}")
            ->assertStatus(200);

        $this->assertSoftDeleted('poultry_mortality_reports', ['id' => $report->id]);
        $this->assertNull(PoultryMortalityReport::find($report->id));
    }
}
