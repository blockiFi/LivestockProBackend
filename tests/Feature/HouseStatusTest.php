<?php

namespace Tests\Feature;

use App\Models\Farm;
use App\Models\Country;
use App\Models\FlockStage;
use App\Models\PoultryHouse;
use App\Models\Permission;
use App\Models\PoultryType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HouseStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private string $token;
    private PoultryType $poultryType;
    private FlockStage $flockStage;
    private PoultryHouse $houseA;
    private PoultryHouse $houseB;

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

        $this->poultryType = PoultryType::factory()->create();

        $this->flockStage = FlockStage::factory()->create([
            'poultry_type_id' => $this->poultryType->id,
        ]);
        // Ensure flock creation finds a stage for arrival_age_days=1.
        $this->flockStage->from_age = 0;
        $this->flockStage->to_age = 30;
        $this->flockStage->save();

        $this->houseA = PoultryHouse::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_type_id' => $this->poultryType->id,
            'capacity' => 1000,
            'status' => 'empty',
        ]);

        $this->houseB = PoultryHouse::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_type_id' => $this->poultryType->id,
            'capacity' => 1000,
            'status' => 'empty',
        ]);
    }

    private function flockPayloadForHouse(PoultryHouse $house, int $quantity = 300): array
    {
        return [
            'farm_id' => $this->farm->id,
            'house_id' => $house->id,
            'poultry_type_id' => $this->poultryType->id,
            'flock_stage_id' => $this->flockStage->id,
            'name' => 'Batch 1',
            'breed' => 'Agrited',
            'source' => 'Elekko',
            'quantity' => $quantity,
            'arrival_date' => now()->format('Y-m-d'),
            'arrival_age_days' => 1,
            'expected_end_date' => now()->addDays(60)->format('Y-m-d'),
            'notes' => '',
        ];
    }

    public function test_house_becomes_active_on_flock_creation()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flocks", $this->flockPayloadForHouse($this->houseA));

        $response->assertStatus(201);
        $this->assertDatabaseHas('poultry_houses', [
            'id' => $this->houseA->id,
            'status' => 'active',
        ]);
    }

    public function test_house_goes_back_to_empty_on_flock_deletion()
    {
        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flocks", $this->flockPayloadForHouse($this->houseA));

        $createResponse->assertStatus(201);
        $flockId = $createResponse->json('data.id');
        $this->assertNotEmpty($flockId);

        $deleteResponse = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson("/api/farms/{$this->farm->id}/flocks/{$flockId}");

        $deleteResponse->assertStatus(200);

        $this->assertDatabaseHas('poultry_houses', [
            'id' => $this->houseA->id,
            'status' => 'empty',
        ]);
    }

    public function test_house_status_updates_on_transfer()
    {
        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flocks", $this->flockPayloadForHouse($this->houseA));

        $createResponse->assertStatus(201);
        $flockId = $createResponse->json('data.id');
        $this->assertNotEmpty($flockId);

        $payload = [
            'transfer_date' => now()->format('Y-m-d'),
            'note' => null,
            'lines' => [
                [
                    'from_house_id' => $this->houseA->id,
                    'to_house_id' => $this->houseB->id,
                    'quantity' => 300,
                ],
            ],
        ];

        $transferResponse = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flocks/{$flockId}/transfers", $payload);

        $transferResponse->assertStatus(201);

        $this->assertDatabaseHas('poultry_houses', [
            'id' => $this->houseA->id,
            'status' => 'empty',
        ]);

        $this->assertDatabaseHas('poultry_houses', [
            'id' => $this->houseB->id,
            'status' => 'active',
        ]);
    }

    public function test_house_status_is_not_overridden_for_manual_states()
    {
        // Put houseA into a manual state.
        $this->houseA->update(['status' => 'maintenance']);

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flocks", $this->flockPayloadForHouse($this->houseA));

        $createResponse->assertStatus(201);

        $this->assertDatabaseHas('poultry_houses', [
            'id' => $this->houseA->id,
            'status' => 'maintenance',
        ]);
    }

    public function test_inactive_house_status_is_not_overridden_when_birds_allocated()
    {
        $this->houseA->update(['status' => 'inactive']);

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flocks", $this->flockPayloadForHouse($this->houseA));

        $createResponse->assertStatus(201);

        $this->assertDatabaseHas('poultry_houses', [
            'id' => $this->houseA->id,
            'status' => 'inactive',
        ]);
    }

    public function test_house_becomes_empty_when_flock_marked_sold()
    {
        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flocks", $this->flockPayloadForHouse($this->houseA));

        $createResponse->assertStatus(201);
        $flockId = $createResponse->json('data.id');

        $this->assertDatabaseHas('poultry_houses', [
            'id' => $this->houseA->id,
            'status' => 'active',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/farms/{$this->farm->id}/flocks/{$flockId}/status", [
                'status' => 'sold',
                'actual_end_date' => now()->format('Y-m-d'),
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('poultry_houses', [
            'id' => $this->houseA->id,
            'status' => 'empty',
        ]);
    }

    public function test_house_becomes_empty_when_flock_marked_completed()
    {
        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flocks", $this->flockPayloadForHouse($this->houseA));

        $createResponse->assertStatus(201);
        $flockId = $createResponse->json('data.id');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/farms/{$this->farm->id}/flocks/{$flockId}/status", [
                'status' => 'completed',
                'actual_end_date' => now()->format('Y-m-d'),
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('poultry_houses', [
            'id' => $this->houseA->id,
            'status' => 'empty',
        ]);
    }

    public function test_shared_house_stays_active_when_one_of_two_flocks_is_sold()
    {
        $first = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flocks", $this->flockPayloadForHouse($this->houseA, 100));
        $first->assertStatus(201);

        $secondPayload = $this->flockPayloadForHouse($this->houseA, 100);
        $secondPayload['name'] = 'Batch 2';
        $second = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flocks", $secondPayload);
        $second->assertStatus(201);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/farms/{$this->farm->id}/flocks/{$first->json('data.id')}/status", [
                'status' => 'sold',
                'actual_end_date' => now()->format('Y-m-d'),
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('poultry_houses', [
            'id' => $this->houseA->id,
            'status' => 'active',
        ]);
    }

    public function test_poultry_house_index_includes_zero_occupancy_for_vacant_active_pen(): void
    {
        Permission::firstOrCreate(['name' => 'view poultry houses', 'guard_name' => 'api']);
        $this->user->roles()->first()->givePermissionTo('view poultry houses');

        $layerType = PoultryType::factory()->create(['name' => 'Layer']);
        $house = PoultryHouse::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_type_id' => $layerType->id,
            'status' => 'active',
            'capacity' => 500,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/poultry-houses/{$this->farm->id}");

        $response->assertStatus(200);
        $match = collect($response->json('data'))->firstWhere('id', $house->id);
        $this->assertNotNull($match);
        $this->assertSame(0, (int) $match['current_occupancy']);
    }
}

