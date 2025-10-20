<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Farm;
use App\Models\Country;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use DB;
use App\Models\PoultryEvent;

class FarmTest extends TestCase
{
    
        use RefreshDatabase, WithFaker;

    protected $user;
    protected $token;
    protected $farm;
    protected $country;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test user
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;
        
        // Create test country
        $this->country = Country::factory()->create();
        
        // Create test farm
        $this->farm = Farm::factory()->create([
            'country_id' => $this->country->id,
            'created_by' => $this->user->id
        ]);
        
        // Attach farm to user
        $this->farm->users()->attach($this->user->id);
        
        // Create permissions
        $permissions = [
           'view farm', 'update farm', 'delete farm', 'manage users',
                'view statistics', 'manage poultry houses', 'manage flocks',
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
    }

    //     parent::setUp();
        
    //     // Create test user
    //     $this->user = User::factory()->create();
    //     $this->token = $this->user->createToken('test-token')->plainTextToken;
        
    //     // Create test country
    //     $this->country = Country::factory()->create();
        
    //     // Create test farm
    //     $this->farm = Farm::factory()->create([
    //         'country_id' => $this->country->id,
    //         'created_by' => $this->user->id
    //     ]);
        
    //     // Attach farm to user
    //     $this->farm->users()->attach($this->user->id);
        
    //     // Define roles and their permissions
    //     $roles = [
    //         'owner' => [
    //             'view farm', 'update farm', 'delete farm', 'manage users',
    //             'view statistics', 'manage poultry houses', 'manage flocks',
    //             'manage inventory', 'manage schedules', 'manage sales'
    //         ],
    //         'manager' => [
    //             'view farm', 'update farm', 'manage users',
    //             'view statistics', 'manage poultry houses', 'manage flocks',
    //             'manage inventory', 'manage schedules', 'manage sales'
    //         ],
    //         'worker' => [
    //             'view farm', 'view statistics',
    //             'manage poultry houses', 'manage flocks',
    //             'manage inventory', 'manage schedules'
    //         ]
    //     ];

    //     // Create all roles and their permissions
    //     foreach ($roles as $roleName => $permissions) {
    //         // Create role with farm_id
    //         $role = Role::findOrCreate(
    //             $roleName,
    //         'api',
    //         $this->farm->id
    //         );
            
    //         // Create and assign permissions for this role
    //         foreach ($permissions as $permission) {
    //             $permissionModel = Permission::findOrCreate(
    //                  $permission,
    //                 'api',
    //                 $this->farm->id
    //            );
    //         }
            
    //         // Sync permissions to role
    //         $role->syncPermissions($permissions);
    //     }

    //     // Assign owner role to the test user
    //     $ownerRole = Role::where('name', 'owner')
    //         ->where('farm_id', $this->farm->id)
    //         ->first();
            
        

    //     $this->user->assignRole($ownerRole);

    //     if (!$this->user->can('manage users')) {
    //         throw new \Exception('Owner does not have manage users permission');
    //     }
    // }

    
    public function test_unauthenticated_user_cannot_access_farm_routes()
    {
        $routes = [
            ['GET', '/api/farms'],
            ['POST', '/api/farms'],
            ['GET', '/api/farms/1'],
            ['PUT', '/api/farms/1'],
            ['DELETE', '/api/farms/1'],
            ['GET', '/api/farms/1/statistics'],
            ['POST', '/api/farms/1/users'],
            ['DELETE', '/api/farms/1/users']
        ];

        foreach ($routes as $route) {
            $response = $this->json($route[0], $route[1]);
            $response->assertStatus(401);
        }
    }

    
    public function test_user_can_get_their_farms()
    {
        // Create additional farms
        $farms = Farm::factory()->count(2)->create([
            'country_id' => $this->country->id,
            'created_by' => $this->user->id
        ]);
        
        // Attach farms to user
        foreach ($farms as $farm) {
            $farm->users()->attach($this->user->id);
        }

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/farms');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'address',
                        'phone',
                        'email',
                        'country_id',
                        'state',
                        'city',
                        'postal_code',
                        'website',
                        'logo',
                        'established_date',
                        'size_hectares',
                        'registration_number',
                        'created_by',
                        'created_at',
                        'updated_at'
                    ]
                ],
                'message'
            ]);

        $this->assertCount(3, $response->json('data'));
    }

    
    public function test_user_can_create_farm()
    {
        Storage::fake('public');

        $farmData = [
            'name' => $this->faker->company,
            'address' => $this->faker->address,
            'phone' => $this->faker->phoneNumber,
            'email' => $this->faker->companyEmail,
            'country_id' => $this->country->id,
            'state' => $this->faker->state,
            'city' => $this->faker->city,
            'postal_code' => $this->faker->postcode,
            'website' => $this->faker->url,
            'logo' => UploadedFile::fake()->image('farm.jpg'),
            'established_date' => $this->faker->date(),
            'size_hectares' => $this->faker->randomFloat(2, 1, 1000),
            'registration_number' => $this->faker->unique()->numerify('FARM-####')
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/farms', $farmData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'address',
                    'phone',
                    'email',
                    'country_id',
                    'state',
                    'city',
                    'postal_code',
                    'website',
                    'logo',
                    'established_date',
                    'size_hectares',
                    'registration_number',
                    'created_by',
                    'created_at',
                    'updated_at'
                ],
                'message'
            ]);

        Storage::disk('public')->assertExists('farm-logos/' . basename($response->json('data.logo')));
    }

    
    public function test_user_can_get_specific_farm()
    {
        
        
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/farms/' . $this->farm->id);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'address',
                    'phone',
                    'email',
                    'country_id',
                    'state',
                    'city',
                    'postal_code',
                    'website',
                    'logo',
                    'established_date',
                    'size_hectares',
                    'registration_number',
                    'created_by',
                    'created_at',
                    'updated_at'
                ],
                'message'
            ]);
    }

    
    public function test_user_can_update_farm()
    {
        Storage::fake('public');

        $updateData = [
            'name' => 'Updated Farm Name',
            'address' => 'Updated Address',
            'phone' => '1234567890',
            'email' => 'updated@farm.com'
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson('/api/farms/' . $this->farm->id, $updateData);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'address',
                    'phone',
                    'email',
                    'country_id',
                    'state',
                    'city',
                    'postal_code',
                    'website',
                    'logo',
                    'established_date',
                    'size_hectares',
                    'registration_number',
                    'created_by',
                    'created_at',
                    'updated_at'
                ],
                'message'
            ]);

        $this->assertEquals('Updated Farm Name', $response->json('data.name'));
    }

    
    public function test_user_can_delete_farm()
    {
        // First verify the farm exists
        $this->assertDatabaseHas('farms', ['id' => $this->farm->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson('/api/farms/' . $this->farm->id);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Farm deleted successfully'
            ]);

        // Verify farm was soft deleted
        $this->assertSoftDeleted($this->farm);

        // Verify farm deletion event was recorded
        $this->assertDatabaseHas('poultry_events', [
            'farm_id' => $this->farm->id,
            'event_type' => 'farm_deletion',
            'table_name' => 'farm',
            'table_id' => $this->farm->id
        ]);
    }

    
    public function test_user_can_get_farm_statistics()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/farms/' . $this->farm->id . '/statistics');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'total_poultry_houses',
                    'total_flocks',
                    'total_customers',
                    'total_sales',
                    'active_schedules',
                    'total_medication_inventory',
                    'total_vaccine_inventory',
                    'total_feed_inventory'
                ],
                'message'
            ]);
    }

    
    public function test_user_can_add_user_to_farm()
    {
        $newUser = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/farms/' . $this->farm->id . '/users', [
                'user_id' => $newUser->id,
                'role' => 'worker'
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'message'
            ]);

        $this->assertDatabaseHas('farm_users', [
            'farm_id' => $this->farm->id,
            'user_id' => $newUser->id
        ]);
    }

    
    public function test_user_can_remove_user_from_farm()
    {
        $userToRemove = User::factory()->create();
        $this->farm->users()->attach($userToRemove->id);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson('/api/farms/' . $this->farm->id . '/users', [
                'user_id' => $userToRemove->id
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'message'
            ]);

        $this->assertDatabaseMissing('farm_users', [
            'farm_id' => $this->farm->id,
            'user_id' => $userToRemove->id
        ]);
    }

    
    public function test_user_without_permission_cannot_update_farm()
    {
        // Create a new user without update permission
        $newUser = User::factory()->create();
        $this->farm->users()->attach($newUser->id);
        
        // Create worker role with limited permissions
        $workerRole = Role::findOrCreate(
           'worker',
            'api',
          $this->farm->id
        );

        // Give only view permission to worker role
        $workerRole->givePermissionTo(['view farm']);
        
        // Assign role with model_type
        $newUser->roles()->attach($workerRole->id, ['model_type' => User::class]);

        $token = $newUser->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/farms/' . $this->farm->id, [
                'name' => 'Updated Name'
            ]);

        $response->assertStatus(403);
    }

    
    public function test_validation_fails_for_invalid_farm_data()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/farms', [
                'name' => '', // Invalid empty name
                'email' => 'invalid-email', // Invalid email
                'phone' => '123' // Invalid phone
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'message',
                'errors'
            ]);
    }
  
    // public function test_user_from_another_farm_cannot_access_or_modify_this_farm()
    // {
    //     // Create another user and their farm
    //     $otherUser = User::factory()->create();
    //     $otherFarm = Farm::factory()->create([
    //         'country_id' => $this->country->id,
    //         'created_by' => $otherUser->id,
    //     ]);
    //     $otherFarm->users()->attach($otherUser->id);
    
    //     // Set permission team context to otherFarm for role and permission creation
    //     app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($otherFarm->id);
    
    //     // Create or fetch role under this farm (team context)
    //     $otherRole = Role::findOrCreate('owner', 'api');
    //     $permissions = ['view farm', 'update farm', 'delete farm', 'manage users'];
    
    //     foreach ($permissions as $permission) {
    //         app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($otherFarm->id);
    //         \Spatie\Permission\Models\Permission::findOrCreate($permission, 'api');
    //     }
    
    //     // Give permissions to role (scoped to other farm)
    //     app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($otherFarm->id);
    //     $otherRole->givePermissionTo($permissions);
    
    //     // Assign role to otherUser under team context (otherFarm)
    //     app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($otherFarm->id);
    //     $otherUser->assignRole($otherRole); // This works correctly because team context was already set above
    
    //     // Generate token
    //     $otherToken = $otherUser->createToken('test-token')->plainTextToken;
    
    //     // Define endpoints the user should not access on $this->farm
    //     // app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->farm->id);
    //     $this->assertTrue($otherUser->hasPermissionTo('view farm'));
    //     // $this->assertFalse($otherUser->hasPermissionTo('view farm', 'web')); // if using 'api' guard
    //     $unauthorizedEndpoints = [
    //         ['GET', '/api/farms/' . $this->farm->id],
    //         ['PUT', '/api/farms/' . $this->farm->id, ['name' => 'New Name']],
    //         ['DELETE', '/api/farms/' . $this->farm->id],
    //         ['GET', '/api/farms/' . $this->farm->id . '/statistics'],
    //         ['POST', '/api/farms/' . $this->farm->id . '/users', ['user_id' => $this->user->id, 'role' => 'worker']],
    //         ['DELETE', '/api/farms/' . $this->farm->id . '/users', ['user_id' => $this->user->id]],
    //     ];
    
    //     // Ensure otherUser cannot access or modify this->farm
    //     foreach ($unauthorizedEndpoints as $endpoint) {
    //         [$method, $url] = $endpoint;
    //         $payload = $endpoint[2] ?? [];
    //         $response = $this->withHeader('Authorization', 'Bearer ' . $otherToken)
    //                          ->json($method, $url, $payload);
    //         $response->assertStatus(403, "Failed asserting $method $url was forbidden");
    //     }
    // }

    public function test_worker_from_another_farm_cannot_access_this_farm()
    {
        $otherUser = User::factory()->create();
        $otherFarm = Farm::factory()->create([
            'country_id' => $this->country->id,
            'created_by' => $otherUser->id
        ]);
        $otherFarm->users()->attach($otherUser->id);

        // Create worker role for other farm
        $workerRole = Role::findOrCreate(
            'worker',
            'api',
            $otherFarm->id
        );
        
        $workerPermissions = ['view farm', 'view statistics'];
        foreach ($workerPermissions as $permission) {
            Permission::findOrCreate(
                $permission,
                'api',
                $otherFarm->id
            );
        }

        $workerRole->givePermissionTo($workerPermissions);
        $otherUser->roles()->attach($workerRole->id, ['model_type' => User::class]);
        $otherToken = $otherUser->createToken('test-token')->plainTextToken;

        // Worker should not be able to access any endpoints of another farm
        $response = $this->withHeader('Authorization', 'Bearer ' . $otherToken)
            ->getJson('/api/farms/' . $this->farm->id);

        $response->assertStatus(403);
    }

    public function test_manager_from_another_farm_cannot_manage_this_farm()
    {
        $otherUser = User::factory()->create();
        $otherFarm = Farm::factory()->create([
            'country_id' => $this->country->id,
            'created_by' => $otherUser->id
        ]);
        $otherFarm->users()->attach($otherUser->id);

        // Create manager role for other farm
        $managerRole = Role::findOrCreate(
            'manager',
            'api',
            $otherFarm->id
        );
        
        $managerPermissions = ['view farm', 'update farm', 'manage users', 'view statistics'];
        foreach ($managerPermissions as $permission) {
            Permission::findOrCreate(
                $permission,
                'api',
                $otherFarm->id
            );
        }

        $managerRole->givePermissionTo($managerPermissions);
        $otherUser->roles()->attach($managerRole->id, ['model_type' => User::class]);
        $otherToken = $otherUser->createToken('test-token')->plainTextToken;

        // Manager should not be able to manage users of another farm
        $newUser = User::factory()->create();
        $response = $this->withHeader('Authorization', 'Bearer ' . $otherToken)
            ->postJson('/api/farms/' . $this->farm->id . '/users', [
                'user_id' => $newUser->id,
                'role' => 'worker'
            ]);

        $response->assertStatus(403);
    }

    public function test_user_cannot_access_farm_statistics_from_another_farm()
    {
        $otherUser = User::factory()->create();
        $otherFarm = Farm::factory()->create([
            'country_id' => $this->country->id,
            'created_by' => $otherUser->id
        ]);
        $otherFarm->users()->attach($otherUser->id);

        $otherRole = Role::findOrCreate(
            'owner',
            'api',
            $otherFarm->id
        );
        
        $permissions = ['view farm', 'view statistics'];
        foreach ($permissions as $permission) {
            Permission::findOrCreate(
                $permission,
                'api',
                $otherFarm->id
            );
        }

        $otherRole->givePermissionTo($permissions);
        $otherUser->roles()->attach($otherRole->id, ['model_type' => User::class]);
        $otherToken = $otherUser->createToken('test-token')->plainTextToken;

        // User should not be able to view statistics of another farm
        $response = $this->withHeader('Authorization', 'Bearer ' . $otherToken)
            ->getJson('/api/farms/' . $this->farm->id . '/statistics');

        $response->assertStatus(403);
    }

    public function test_user_cannot_delete_farm_from_another_farm()
    {
        $otherUser = User::factory()->create();
        $otherFarm = Farm::factory()->create([
            'country_id' => $this->country->id,
            'created_by' => $otherUser->id
        ]);
        $otherFarm->users()->attach($otherUser->id);

        $otherRole = Role::findOrCreate(
            'owner',
            'api',
            $otherFarm->id
        );
        
        $permissions = ['view farm', 'delete farm'];
        foreach ($permissions as $permission) {
            Permission::findOrCreate(
                $permission,
                'api',
                $otherFarm->id
            );
        }

        $otherRole->givePermissionTo($permissions);
        $otherUser->roles()->attach($otherRole->id, ['model_type' => User::class]);
        $otherToken = $otherUser->createToken('test-token')->plainTextToken;

        // User should not be able to delete another farm
        $response = $this->withHeader('Authorization', 'Bearer ' . $otherToken)
            ->deleteJson('/api/farms/' . $this->farm->id);

        $response->assertStatus(403);

        // Verify the farm still exists
        $this->assertDatabaseHas('farms', ['id' => $this->farm->id]);
    }

    public function test_user_cannot_update_farm_from_another_farm()
    {
        $otherUser = User::factory()->create();
        $otherFarm = Farm::factory()->create([
            'country_id' => $this->country->id,
            'created_by' => $otherUser->id
        ]);
        $otherFarm->users()->attach($otherUser->id);

        $otherRole = Role::findOrCreate(
            'owner',
            'api',
            $otherFarm->id
        );
        
        $permissions = ['view farm', 'update farm'];
        foreach ($permissions as $permission) {
            Permission::findOrCreate(
                $permission,
                'api',
                $otherFarm->id
            );
        }

        $otherRole->givePermissionTo($permissions);
        $otherUser->roles()->attach($otherRole->id, ['model_type' => User::class]);
        $otherToken = $otherUser->createToken('test-token')->plainTextToken;

        $originalName = $this->farm->name;

        // User should not be able to update another farm
        $response = $this->withHeader('Authorization', 'Bearer ' . $otherToken)
            ->putJson('/api/farms/' . $this->farm->id, [
                'name' => 'Unauthorized Update'
            ]);

        $response->assertStatus(403);

        // Verify the farm name hasn't changed
        $this->farm->refresh();
        $this->assertEquals($originalName, $this->farm->name);
    }

    public function test_user_can_upload_and_replace_logo_on_update()
    {
        Storage::fake('public');

        // First create a farm with initial logo
        $initialLogo = UploadedFile::fake()->image('initial-logo.jpg');
        $farmData = [
            'name' => $this->faker->company,
            'address' => $this->faker->address,
            'phone' => $this->faker->phoneNumber,
            'email' => $this->faker->companyEmail,
            'country_id' => $this->country->id,
            'state' => $this->faker->state,
            'city' => $this->faker->city,
            'postal_code' => $this->faker->postcode,
            'website' => $this->faker->url,
            'logo' => $initialLogo,
            'established_date' => $this->faker->date(),
            'size_hectares' => $this->faker->randomFloat(2, 1, 1000),
            'registration_number' => $this->faker->unique()->numerify('FARM-####')
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/farms', $farmData);

        $response->assertStatus(201);
        $initialLogoPath = $response->json('data.logo');
        Storage::disk('public')->assertExists('farm-logos/' . basename($initialLogoPath));
        $prevFarm = Farm::find($this->farm->id);
        // Now update the farm with a new logo
        $newLogo = UploadedFile::fake()->image('new-logo.jpg');
        $updateData = [
            'name' => 'Updated Farm Name',
            'logo' => $newLogo
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson('/api/farms/' . $this->farm->id, $updateData);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'logo',
                    // ... other fields ...
                ],
                'message'
            ]);

            $updatedFarm = Farm::find($this->farm->id);
            Storage::disk('public')->assertExists($updatedFarm->logo);
            Storage::disk('public')->assertMissing($prevFarm->logo);
        
    }

    // 
    public function test_only_users_with_manage_users_can_add_or_remove_users()
{
    // Create a regular user without 'manage users' permission
    $regularUser = User::factory()->create();
    $this->farm->users()->attach($regularUser->id);

    // Create 'worker' role for the farm and assign only 'view farm' permission
    $workerRole = Role::findOrCreate('worker', 'api', $this->farm->id);
    $viewPermission = Permission::findOrCreate('view farm', 'api', $this->farm->id);
    $workerRole->givePermissionTo($viewPermission);

    // Assign role using direct DB insert (supports farm_id multi-tenancy)
    DB::table('model_has_roles')->insert([
        'role_id' => $workerRole->id,
        'model_type' => User::class,
        'model_id' => $regularUser->id
    ]);

    $regularUserToken = $regularUser->createToken('test-token')->plainTextToken;

    // Create a target user to be managed
    $userToManage = User::factory()->create();

    // Regular user tries to add a user -> should be forbidden
    $response = $this->withHeader('Authorization', 'Bearer ' . $regularUserToken)
        ->postJson('/api/farms/' . $this->farm->id . '/users', [
            'user_id' => $userToManage->id,
            'role' => 'worker'
        ]);
    $response->assertStatus(403);

    // Regular user tries to remove a user -> should be forbidden
    $response = $this->withHeader('Authorization', 'Bearer ' . $regularUserToken)
        ->deleteJson('/api/farms/' . $this->farm->id . '/users', [
            'user_id' => $userToManage->id
        ]);
    $response->assertStatus(403);

    // Owner (has 'manage users') tries to add the user -> should succeed
    $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
        ->postJson('/api/farms/' . $this->farm->id . '/users', [
            'user_id' => $userToManage->id,
            'role' => 'worker'
        ]);

    // $response->assertStatus(200)->assertJsonStructure([
    //     'data',
    //     'message'
    // ]);

    // $this->assertDatabaseHas('farm_users', [
    //     'farm_id' => $this->farm->id,
    //     'user_id' => $userToManage->id
    // ]);

    // Owner removes the user -> should succeed
    // $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
    //     ->deleteJson('/api/farms/' . $this->farm->id . '/users', [
    //         'user_id' => $userToManage->id
    //     ]);

    // $response->assertStatus(200)->assertJsonStructure([
    //     'data',
    //     'message'
    // ]);

    // $this->assertDatabaseMissing('farm_users', [
    //     'farm_id' => $this->farm->id,
    //     'user_id' => $userToManage->id
    // ]);
}

    public function test_farm_creation_event_is_registered()
    {
        $farmData = [
            'name' => 'Test Farm for Events',
            'address' => '123 Test St',
            'phone' => '1234567890',
            'email' => 'test@farm.com',
            'country_id' => $this->country->id,
            'state' => 'Test State',
            'city' => 'Test City',
            'postal_code' => '12345',
            'established_date' => '2024-01-01',
            'size_hectares' => 100,
            'registration_number' => 'FARM789'
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/farms', $farmData);

        $farmId = $response->json('data.id');

        // Get the event
        $event = PoultryEvent::where('farm_id', $farmId)
            ->where('event_type', 'farm_creation')
            ->first();

        // Verify event data
        $this->assertNotNull($event);
        $this->assertEquals('farm_creation', $event->event_type);
        $this->assertEquals('farm', $event->table_name);
        $this->assertEquals($farmId, $event->table_id);
        $this->assertEquals($this->user->id, $event->performed_by);
        $this->assertNotNull($event->event_date);
        $this->assertStringContainsString('farm_creation performed on farm', $event->event);
    }

    public function test_farm_update_event_is_registered()
    {
        $updateData = [
            'name' => 'Updated Farm Name',
            'address' => '456 New Address St'
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/farms/{$this->farm->id}", $updateData);

        // Get the event
        $event = PoultryEvent::where('farm_id', $this->farm->id)
            ->where('event_type', 'farm_update')
            ->first();

        // Verify event data
        $this->assertNotNull($event);
        $this->assertEquals('farm_update', $event->event_type);
        $this->assertEquals('farm', $event->table_name);
        $this->assertEquals($this->farm->id, $event->table_id);
        $this->assertEquals($this->user->id, $event->performed_by);
        $this->assertNotNull($event->event_date);
        $this->assertStringContainsString('farm_update performed on farm', $event->event);
    }

    public function test_farm_deletion_event_is_registered()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson("/api/farms/{$this->farm->id}");

        // Get the event
        $event = PoultryEvent::where('farm_id', $this->farm->id)
            ->where('event_type', 'farm_deletion')
            ->first();

        // Verify event data
        $this->assertNotNull($event);
        $this->assertEquals('farm_deletion', $event->event_type);
        $this->assertEquals('farm', $event->table_name);
        $this->assertEquals($this->farm->id, $event->table_id);
        $this->assertEquals($this->user->id, $event->performed_by);
        $this->assertNotNull($event->event_date);
        $this->assertStringContainsString('farm_deletion performed on farm', $event->event);
    }

    public function test_user_added_event_is_registered()
    {
        $newUser = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/users", [
                'user_id' => $newUser->id,
                'role' => 'worker'
            ]);

        // Get the event
        $event = PoultryEvent::where('farm_id', $this->farm->id)
            ->where('event_type', 'user_added')
            ->first();

        // Verify event data
        $this->assertNotNull($event);
        $this->assertEquals('user_added', $event->event_type);
        $this->assertEquals('user', $event->table_name);
        $this->assertEquals($newUser->id, $event->table_id);
        $this->assertEquals($this->user->id, $event->performed_by);
        $this->assertNotNull($event->event_date);
        $this->assertStringContainsString('user_added performed on user', $event->event);
    }

    public function test_user_removed_event_is_registered()
    {
        $userToRemove = User::factory()->create();
        $this->farm->users()->attach($userToRemove->id);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson("/api/farms/{$this->farm->id}/users", [
                'user_id' => $userToRemove->id
            ]);

        // Get the event
        $event = PoultryEvent::where('farm_id', $this->farm->id)
            ->where('event_type', 'user_removed')
            ->first();

        // Verify event data
        $this->assertNotNull($event);
        $this->assertEquals('user_removed', $event->event_type);
        $this->assertEquals('user', $event->table_name);
        $this->assertEquals($userToRemove->id, $event->table_id);
        $this->assertEquals($this->user->id, $event->performed_by);
        $this->assertNotNull($event->event_date);
        $this->assertStringContainsString('user_removed performed on user', $event->event);
    }

    public function test_event_data_integrity()
    {
        // Create a new farm to test event data
        $farmData = [
            'name' => 'Test Farm for Events',
            'address' => '123 Test St',
            'phone' => '1234567890',
            'email' => 'test@farm.com',
            'country_id' => $this->country->id,
            'state' => 'Test State',
            'city' => 'Test City',
            'postal_code' => '12345',
            'established_date' => '2024-01-01',
            'size_hectares' => 100,
            'registration_number' => 'FARM456'
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/farms', $farmData);

        $farmId = $response->json('data.id');

        // Get the event
        $event = PoultryEvent::where('farm_id', $farmId)
            ->where('event_type', 'farm_creation')
            ->first();

        // Verify event data integrity
        $this->assertNotNull($event);
        $this->assertEquals('farm_creation', $event->event_type);
        $this->assertEquals('farm', $event->table_name);
        $this->assertEquals($farmId, $event->table_id);
        $this->assertEquals($this->user->id, $event->performed_by);
        $this->assertNotNull($event->event_date);
        $this->assertStringContainsString('farm_creation performed on farm', $event->event);

        // Verify event description contains farm data
        $this->assertStringContainsString('Test Farm for Events', $event->event);
        $this->assertStringContainsString('123 Test St', $event->event);
    }

} 