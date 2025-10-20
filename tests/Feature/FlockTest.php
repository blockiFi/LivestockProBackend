<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\PoultryType;
use App\Models\PoultryHouse;
use App\Models\FlockStage;
use App\Models\PoultryEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class FlockTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $farm;
    protected $token;
    protected $poultryType;
    protected $poultryHouse;
    protected $flockStage;

    protected function setUp(): void
    {
        parent::setUp();

        // Create permissions
        $permissions = [
            'view flocks', 'update flocks', 'delete flock', 'manage flocks',
            'view statistics', 'manage poultry houses', 'manage inventory',
            'manage schedules', 'manage sales'
        ];

        // Create test user
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;

        // Create test farm
        $this->farm = Farm::factory()->create([
            'created_by' => $this->user->id
        ]);

        // Create permissions for the farm
        $permissions = [
            'view farm', 'update farm', 'delete farm', 'manage users',
                 'view statistics', 'manage poultry houses', 'view flocks', 'manage flocks',
                 'manage inventory', 'manage schedules', 'manage sales'
         ];
 
         foreach ($permissions as $permission) {
             Permission::findOrCreate(
                                  $permission,
                                 'api',
                                 $this->farm->id
                            );
         }
         
         // Create and assign owner role
         $ownerRole = Role::findOrCreate(
                     'owner',
                     'api',
                     $this->farm->id
                     );
 
         // Give all permissions to owner role
         $ownerRole->givePermissionTo($permissions);
         
         // Assign role with model_type
         $this->user->roles()->attach($ownerRole->id, ['model_type' => User::class]);

        // Create test poultry type
        $this->poultryType = PoultryType::factory()->create();

        // Create test poultry house
        $this->poultryHouse = PoultryHouse::factory()->create([
            'farm_id' => $this->farm->id
        ]);

        // Create test flock stage
        $this->flockStage = FlockStage::factory()->create(
            [
                'poultry_type_id' => $this->poultryType,
            ]
        );

        // Create test flock
        $this->flock = Flock::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_weight_report_frequency_id' => null,
            'house_id' => $this->poultryHouse->id,
            'poultry_type_id' => $this->poultryType->id,
            'flock_stage_id' => $this->flockStage->id
        ]);
    }

    public function test_can_list_flocks()
    {
        // Create some flocks
        Flock::factory()->count(3)->create([
            'farm_id' => $this->farm->id,
            'house_id' => $this->poultryHouse->id,
            'poultry_type_id' => $this->poultryType->id,
            'flock_stage_id' => $this->flockStage->id
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/flocks");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'current_page',
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'batch_number',
                            'breed',
                            'source',
                            'quantity',
                            'status',
                            'poultry_type',
                            'flock_stage',
                            'poultry_house'
                        ]
                    ]
                ],
                'message'
            ]);
    }

    public function test_can_create_flock()
    {
        $flockData = [
            'farm_id' => $this->farm->id,
            'house_id' => $this->poultryHouse->id,
            'poultry_type_id' => $this->poultryType->id,
            'flock_stage_id' => $this->flockStage->id,
            'name' => 'Test Flock',
            'batch_number' => 'BATCH001',
            'breed' => 'Test Breed',
            'source' => 'Test Source',
            'quantity' => 100,
            'arrival_date' => now()->format('Y-m-d'),
            'arrival_age_days' => 1,
            'expected_end_date' => now()->addMonths(6)->format('Y-m-d'),
            'notes' => 'Test notes'
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flocks", $flockData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'name',
                    'batch_number',
                    'breed',
                    'source',
                    'quantity',
                    'status',
                    'poultry_type',
                    'flock_stage',
                    'poultry_house'
                ],
                'message'
            ]);

        $this->assertDatabaseHas('flocks', [
            'name' => 'Test Flock',
            'batch_number' => 'BATCH001'
        ]);

        // Verify event was created
        $this->assertDatabaseHas('poultry_events', [
            'farm_id' => $this->farm->id,
            'event_type' => 'flock_creation',
            'table_name' => 'flock'
        ]);
    }

    public function test_can_view_flock()
    {
        $flock = Flock::factory()->create([
            'farm_id' => $this->farm->id,
            'house_id' => $this->poultryHouse->id,
            'poultry_type_id' => $this->poultryType->id,
            'flock_stage_id' => $this->flockStage->id
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/flocks/{$flock->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'name',
                    'batch_number',
                    'breed',
                    'source',
                    'quantity',
                    'status',
                    'poultry_type',
                    'flock_stage',
                    'poultry_house',
                    'daily_records',
                    'mortality_reports',
                    'weight_reports',
                    'egg_reports',
                    'batch_schedules',
                    'poultry_feed_usages',
                    'poultry_medication_records'
                ],
                'message'
            ]);
    }

    public function test_can_update_flock()
    {
        $flock = Flock::factory()->create([
            'farm_id' => $this->farm->id,
            'house_id' => $this->poultryHouse->id,
            'poultry_type_id' => $this->poultryType->id,
            'flock_stage_id' => $this->flockStage->id
        ]);

        $updateData = [
            'name' => 'Updated Flock Name',
            'quantity' => 150,
            'notes' => 'Updated notes'
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/farms/{$this->farm->id}/flocks/{$flock->id}", $updateData);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'name',
                    'batch_number',
                    'breed',
                    'source',
                    'quantity',
                    'status',
                    'poultry_type',
                    'flock_stage',
                    'poultry_house'
                ],
                'message'
            ]);

        $this->assertDatabaseHas('flocks', [
            'id' => $flock->id,
            'name' => 'Updated Flock Name',
            'quantity' => 150
        ]);

        // Verify event was created
        $this->assertDatabaseHas('poultry_events', [
            'farm_id' => $this->farm->id,
            'flock_id' => $flock->id,
            'event_type' => 'flock_update',
            'table_name' => 'flock'
        ]);
    }

    public function test_can_delete_flock()
    {
        $flock = Flock::factory()->create([
            'farm_id' => $this->farm->id,
            'house_id' => $this->poultryHouse->id,
            'poultry_type_id' => $this->poultryType->id,
            'flock_stage_id' => $this->flockStage->id
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson("/api/farms/{$this->farm->id}/flocks/{$flock->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
                'message'
            ]);

        $this->assertSoftDeleted('flocks', ['id' => $flock->id]);

        // Verify event was created
        $this->assertDatabaseHas('poultry_events', [
            'farm_id' => $this->farm->id,
            'flock_id' => $flock->id,
            'event_type' => 'flock_deletion',
            'table_name' => 'flock'
        ]);
    }

    public function test_can_get_flock_statistics()
    {
        $flock = Flock::factory()->create([
            'farm_id' => $this->farm->id,
            'house_id' => $this->poultryHouse->id,
            'poultry_type_id' => $this->poultryType->id,
            'flock_stage_id' => $this->flockStage->id
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/flocks/{$flock->id}/statistics");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_mortality',
                    'total_eggs',
                    'average_weight',
                    'total_feed_consumed',
                    'total_medications',
                    'current_count',
                    'production_rate'
                ],
                'message'
            ]);
    }

    public function test_can_get_flock_timeline()
    {
        $flock = Flock::factory()->create([
            'farm_id' => $this->farm->id,
            'house_id' => $this->poultryHouse->id,
            'poultry_type_id' => $this->poultryType->id,
            'flock_stage_id' => $this->flockStage->id
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/flocks/{$flock->id}/timeline");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'farm_id',
                        'flock_id',
                        'event_type',
                        'table_name',
                        'table_id',
                        'event_date',
                        'event',
                        'performed_by'
                    ]
                ],
                'message'
            ]);
    }

    public function test_can_update_flock_status()
    {
        $flock = Flock::factory()->create([
            'farm_id' => $this->farm->id,
            'house_id' => $this->poultryHouse->id,
            'poultry_type_id' => $this->poultryType->id,
            'flock_stage_id' => $this->flockStage->id
        ]);

        $updateData = [
            'status' => 'completed',
            'actual_end_date' => now()->format('Y-m-d')
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/farms/{$this->farm->id}/flocks/{$flock->id}/status", $updateData);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'status',
                    'actual_end_date'
                ],
                'message'
            ]);

        $this->assertDatabaseHas('flocks', [
            'id' => $flock->id,
            'status' => 'completed'
        ]);

        // Verify event was created
        $this->assertDatabaseHas('poultry_events', [
            'farm_id' => $this->farm->id,
            'flock_id' => $flock->id,
            'event_type' => 'flock_status_update',
            'table_name' => 'flock'
        ]);
    }

    public function test_can_get_flock_performance_metrics()
    {
        $flock = Flock::factory()->create([
            'farm_id' => $this->farm->id,
            'house_id' => $this->poultryHouse->id,
            'poultry_type_id' => $this->poultryType->id,
            'flock_stage_id' => $this->flockStage->id
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/flocks/{$flock->id}/performance");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'mortality_rate',
                    'feed_conversion_ratio',
                    'egg_production_rate',
                    'weight_gain_rate'
                ],
                'message'
            ]);
    }

    public function test_unauthorized_user_cannot_access_flocks()
    {
        // Create a user without permissions
        $unauthorizedUser = User::factory()->create();
        $token = $unauthorizedUser->createToken('test-token')->plainTextToken;

        $flock = Flock::factory()->create([
            'farm_id' => $this->farm->id,
            'house_id' => $this->poultryHouse->id,
            'poultry_type_id' => $this->poultryType->id,
            'flock_stage_id' => $this->flockStage->id
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/farms/{$this->farm->id}/flocks");

        $response->assertStatus(403);
    }

    public function test_validation_errors_on_flock_creation()
    {
        $invalidData = [
            'name' => '', // Required field
            'quantity' => 'not-a-number', // Should be integer
            'arrival_date' => 'invalid-date' // Should be valid date
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flocks", $invalidData);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'message',
                'errors'
            ]);
    }
} 