<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Farm;
use App\Models\Role;
use App\Models\Permission;
use App\Models\PoultryType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;

class PoultryTypeTest extends TestCase
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
            'view poultry types',
            'manage poultry types'
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

    public function test_can_list_poultry_types()
    {
        // Create some poultry types
        PoultryType::factory()->count(3)->create([
            'created_by' => $this->user->id
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/poultry-types');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'scientific_name',
                        'description',
                        'average_lifespan_days',
                        'average_weight_kg',
                        'is_active',
                        'created_at',
                        'updated_at'
                    ]
                ],
                'message'
            ]);
    }

    public function test_can_create_poultry_type()
    {
        $poultryTypeData = [
            'name' => $this->faker->word,
            'scientific_name' => $this->faker->word,
            'description' => $this->faker->sentence,
            'average_lifespan_days' => $this->faker->numberBetween(100, 500),
            'average_weight_kg' => $this->faker->randomFloat(2, 1, 10),
            'is_active' => true
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/poultry-types', $poultryTypeData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'name',
                    'scientific_name',
                    'description',
                    'average_lifespan_days',
                    'average_weight_kg',
                    'is_active',
                    'created_at',
                    'updated_at'
                ],
                'message'
            ]);

        $this->assertDatabaseHas('poultry_types', [
            'name' => $poultryTypeData['name'],
            'scientific_name' => $poultryTypeData['scientific_name']
        ]);
    }

    public function test_can_update_poultry_type()
    {
        $poultryType = PoultryType::factory()->create([
            'created_by' => $this->user->id
        ]);

        $updateData = [
            'name' => 'Updated Name',
            'scientific_name' => 'Updated Scientific Name',
            'description' => 'Updated description',
            'average_lifespan_days' => 300,
            'average_weight_kg' => 5.5,
            'is_active' => false
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->putJson("/api/poultry-types/{$poultryType->id}", $updateData);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'name',
                    'scientific_name',
                    'description',
                    'average_lifespan_days',
                    'average_weight_kg',
                    'is_active',
                    'created_at',
                    'updated_at'
                ],
                'message'
            ]);

        $this->assertDatabaseHas('poultry_types', [
            'id' => $poultryType->id,
            'name' => $updateData['name'],
            'scientific_name' => $updateData['scientific_name']
        ]);
    }

    public function test_can_delete_poultry_type()
    {
        $poultryType = PoultryType::factory()->create([
            'created_by' => $this->user->id
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->deleteJson("/api/poultry-types/{$poultryType->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
                'message'
            ]);

        $this->assertSoftDeleted('poultry_types', [
            'id' => $poultryType->id
        ]);
    }

    public function test_can_get_poultry_type_statistics()
    {
        $poultryType = PoultryType::factory()->create([
            'created_by' => $this->user->id
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson("/api/poultry-types/{$poultryType->id}/statistics");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_flocks',
                    'total_houses',
                    'total_feed_types'
                ],
                'message'
            ]);
    }

    public function test_unauthorized_user_cannot_access_poultry_types()
    {
        // Create a new user without permissions
        $unauthorizedUser = User::factory()->create();
        $token = $unauthorizedUser->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/poultry-types');

        $response->assertStatus(403);
    }

    public function test_validation_errors_on_poultry_type_creation()
    {
        $invalidData = [
            'name' => '', // Required field
            'scientific_name' => '', // Required field
            'average_lifespan_days' => 'not-a-number', // Must be numeric
            'average_weight_kg' => -1, // Must be positive
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/poultry-types', $invalidData);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'data',
                'message'
            ]);
    }
} 