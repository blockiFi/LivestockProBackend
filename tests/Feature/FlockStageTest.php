<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Farm;
use App\Models\Role;
use App\Models\Permission;
use App\Models\FlockStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;

class FlockStageTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $user;
    protected $farm;
    protected $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test user
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;

        // Create test farm
        $this->farm = Farm::factory()->create([
            'created_by' => $this->user->id
        ]);

        // Create permissions
        $permissions = [
            'view flock stages',
            'manage flock stages'
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'api');
        }

        // Create owner role and assign permissions
        $ownerRole = Role::findOrCreate('owner', 'api');
        $ownerRole->givePermissionTo($permissions);
        
        // Assign role with model_type
        $this->user->roles()->attach($ownerRole->id, ['model_type' => User::class]);

        // Attach farm to user
        $this->farm->users()->attach($this->user->id);
    }

    public function test_can_list_flock_stages()
    {
        // Create some flock stages
        FlockStage::factory()->count(3)->create([
            'created_by' => $this->user->id
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/flock-stages');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'description',
                            'status',
                            'created_at',
                            'updated_at'
                        ]
                    ]
                ],
                'message'
            ]);
    }

    public function test_can_create_flock_stage()
    {
        $flockStageData = [
            'name' => $this->faker->word,
            'description' => $this->faker->sentence,
            'status' => 'active'
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/flock-stages', $flockStageData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'name',
                    'description',
                    'status',
                    'created_at',
                    'updated_at'
                ],
                'message'
            ]);

        $this->assertDatabaseHas('flock_stages', [
            'name' => $flockStageData['name'],
            'status' => $flockStageData['status']
        ]);
    }

    public function test_can_update_flock_stage()
    {
        $flockStage = FlockStage::factory()->create([
            'created_by' => $this->user->id
        ]);

        $updateData = [
            'name' => 'Updated Stage',
            'description' => 'Updated description',
            'status' => 'inactive'
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->putJson("/api/flock-stages/{$flockStage->id}", $updateData);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'name',
                    'description',
                    'status',
                    'created_at',
                    'updated_at'
                ],
                'message'
            ]);

        $this->assertDatabaseHas('flock_stages', [
            'id' => $flockStage->id,
            'name' => $updateData['name'],
            'status' => $updateData['status']
        ]);
    }

    public function test_can_delete_flock_stage()
    {
        $flockStage = FlockStage::factory()->create([
            'created_by' => $this->user->id
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->deleteJson("/api/flock-stages/{$flockStage->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
                'message'
            ]);

        $this->assertSoftDeleted('flock_stages', [
            'id' => $flockStage->id
        ]);
    }

    public function test_can_get_flock_stage_statistics()
    {
        $flockStage = FlockStage::factory()->create([
            'created_by' => $this->user->id
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson("/api/flock-stages/{$flockStage->id}/statistics");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_flocks',
                    'active_flocks',
                    'completed_flocks'
                ],
                'message'
            ]);
    }

    public function test_unauthorized_user_cannot_access_flock_stages()
    {
        // Create a new user without permissions
        $unauthorizedUser = User::factory()->create();
        $token = $unauthorizedUser->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/flock-stages');

        $response->assertStatus(403);
    }

    public function test_validation_errors_on_flock_stage_creation()
    {
        $invalidData = [
            'name' => '', // Required field
            'status' => 'invalid_status' // Invalid status
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/flock-stages', $invalidData);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'data',
                'message'
            ]);
    }

    public function test_cannot_delete_flock_stage_in_use()
    {
        $flockStage = FlockStage::factory()->create([
            'created_by' => $this->user->id
        ]);

        // Create a flock using this stage
        $flockStage->flocks()->create([
            'name' => 'Test Flock',
            'farm_id' => $this->farm->id,
            'created_by' => $this->user->id
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->deleteJson("/api/flock-stages/{$flockStage->id}");

        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'data',
                'message'
            ]);

        $this->assertDatabaseHas('flock_stages', [
            'id' => $flockStage->id
        ]);
    }
} 