<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockStage;
use App\Models\Permission;
use App\Models\PoultryHouse;
use App\Models\PoultryType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlockEndedBatchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private Flock $flock;
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

        $permissions = collect(['view flocks', 'update flocks', 'create flock daily records'])
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

        $this->flock = Flock::factory()->create([
            'farm_id' => $this->farm->id,
            'house_id' => $house->id,
            'poultry_type_id' => $poultryType->id,
            'flock_stage_id' => $flockStage->id,
            'quantity' => 100,
            'status' => 'sold',
            'actual_end_date' => now()->toDateString(),
        ]);
    }

    public function test_cannot_create_daily_record_on_ended_batch(): void
    {
        $response = $this->withToken($this->token)->postJson("/api/farms/{$this->farm->id}/flock-daily-records", [
            'flock_id' => $this->flock->id,
            'date' => now()->toDateString(),
            'mortality_count' => 0,
            'culling_count' => 0,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This batch has ended. No further updates are allowed.');
    }

    public function test_cannot_create_sale_on_ended_batch(): void
    {
        $response = $this->withToken($this->token)->postJson(
            "/api/farms/{$this->farm->id}/flocks/{$this->flock->id}/sales",
            [
                'quantity' => 10,
                'unit_price' => 500,
                'date' => now()->toDateString(),
            ]
        );

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This batch has ended. No further updates are allowed.');
    }
}
