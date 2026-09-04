<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockStage;
use App\Models\Permission;
use App\Models\PoultryFlockEggReport;
use App\Models\PoultryHouse;
use App\Models\PoultryType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FlockEggReportTest extends TestCase
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

        $permissions = collect([
            'create flock egg reports',
            'update flock egg reports',
            'delete flock egg reports',
            'view flock egg reports',
        ])->map(fn (string $name) => Permission::firstOrCreate([
            'name' => $name,
            'guard_name' => 'api',
        ]));

        $ownerRole = Role::create([
            'name' => 'owner',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $ownerRole->givePermissionTo($permissions);

        $this->farm->users()->attach($this->user->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->farm->id);
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
            'arrival_date' => now()->subDays(30)->toDateString(),
            'status' => 'active',
        ]);
    }

    public function test_egg_report_store_computes_production_percentage(): void
    {
        $date = now()->toDateString();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/farms/{$this->farm->id}/flock-egg-reports", [
                'flock_id' => $this->flock->id,
                'date' => $date,
                'eggs_collected' => 85,
                'eggs_broken' => 2,
                'average_egg_weight' => 58.5,
                'notes' => 'Morning collection',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.eggs_collected', 85)
            ->assertJsonPath('data.eggs_broken', 2)
            ->assertJsonPath('data.bird_count', 100)
            ->assertJsonPath('data.production_percentage', '85.00')
            ->assertJsonPath('data.recorded_by.id', $this->user->id);

        $this->assertDatabaseHas('poultry_flock_egg_reports', [
            'flock_id' => $this->flock->id,
            'eggs_collected' => 85,
            'eggs_broken' => 2,
            'production_percentage' => 85,
        ]);
    }

    public function test_egg_report_rejects_broken_above_collected(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/farms/{$this->farm->id}/flock-egg-reports", [
                'flock_id' => $this->flock->id,
                'date' => now()->toDateString(),
                'eggs_collected' => 10,
                'eggs_broken' => 12,
            ])
            ->assertStatus(422);
    }

    public function test_egg_report_can_be_updated_and_deleted(): void
    {
        $report = PoultryFlockEggReport::create([
            'flock_id' => $this->flock->id,
            'farm_id' => $this->farm->id,
            'date' => now()->toDateString(),
            'eggs_collected' => 70,
            'eggs_broken' => 1,
            'average_egg_weight' => 57,
            'production_percentage' => 70,
            'bird_count' => 100,
            'notes' => 'Original',
            'recorded_by' => $this->user->id,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson("/api/farms/{$this->farm->id}/flock-egg-reports/{$report->id}", [
                'eggs_collected' => 80,
                'eggs_broken' => 4,
                'average_egg_weight' => 59,
                'notes' => 'Updated',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.eggs_collected', 80)
            ->assertJsonPath('data.eggs_broken', 4)
            ->assertJsonPath('data.production_percentage', '80.00');

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->deleteJson("/api/farms/{$this->farm->id}/flock-egg-reports/{$report->id}")
            ->assertStatus(200);

        $this->assertSoftDeleted('poultry_flock_egg_reports', ['id' => $report->id]);
    }
}
