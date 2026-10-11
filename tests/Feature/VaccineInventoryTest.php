<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Farm;
use App\Models\PoultryVaccineInventory;
use App\Models\PoultryVaccineProduct;
use App\Models\PoultryVaccine;
use App\Models\Country;
use App\Models\AdministrationMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;

class VaccineInventoryTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $user;
    protected $farm;
    protected $vaccine;
    protected $product;
    protected $country;
    protected $administrationMethod;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        $this->user = User::factory()->create();
        $this->farm = Farm::factory()->create(['created_by' => $this->user->id]);
        $this->country = Country::factory()->create();
        $this->administrationMethod = AdministrationMethod::factory()->create();

        // Create vaccine and product
        $this->vaccine = PoultryVaccine::factory()->create([
            'farm_id' => null, // Default vaccine
            'type' => 'default'
        ]);

        $this->product = PoultryVaccineProduct::factory()->create([
            'farm_id' => null, // Default product
            'poultry_vaccine_id' => $this->vaccine->id,
            'administration_method_id' => $this->administrationMethod->id
        ]);

        // Create farm-scoped permissions
        $permissions = [
            'view vaccine inventory',
            'manage vaccine inventory',
        ];

        foreach ($permissions as $permission) {
            Permission::create([
                'name' => $permission,
                'guard_name' => 'api',
                'farm_id' => $this->farm->id
            ]);
        }

        // Create owner role with all permissions
        $ownerRole = Role::create([
            'name' => 'owner',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id
        ]);

        $ownerRole->givePermissionTo($permissions);

        // Assign user to farm with owner role
        $this->farm->users()->attach($this->user->id);
        $this->user->assignRole($ownerRole);
    }

    /** @test */
    public function user_can_view_vaccine_inventory()
    {
        $inventory = PoultryVaccineInventory::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_vaccine_product_id' => $this->product->id,
            'country_id' => $this->country->id,
            'created_by' => $this->user->id
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/vaccine-inventory");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'quantity',
                            'status',
                            'batch_number',
                            'unit_cost',
                            'product' => [
                                'id',
                                'name',
                                'vaccine' => [
                                    'id',
                                    'name'
                                ]
                            ],
                            'country' => [
                                'id',
                                'name'
                            ]
                        ]
                    ]
                ],
                'message'
            ]);
    }

    /** @test */
    public function user_cannot_view_vaccine_inventory_without_permission()
    {
        $userWithoutPermission = User::factory()->create();
        $this->farm->users()->attach($userWithoutPermission->id);

        $response = $this->actingAs($userWithoutPermission, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/vaccine-inventory");

        $response->assertStatus(403);
    }

    /** @test */
    public function user_can_create_vaccine_inventory()
    {
        $inventoryData = [
            'poultry_vaccine_product_id' => $this->product->id,
            'quantity' => 100.50,
            'status' => 'available',
            'batch_number' => 'BATCH001',
            'manufacture_date' => '2024-01-01',
            'expiry_date' => '2025-01-01',
            'unit_cost' => 25.00,
            'country_id' => $this->country->id,
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/farms/{$this->farm->id}/vaccine-inventory", $inventoryData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'quantity',
                    'status',
                    'batch_number',
                    'unit_cost',
                    'product',
                    'country'
                ],
                'message'
            ]);

        $this->assertDatabaseHas('poultry_vaccine_inventories', [
            'farm_id' => $this->farm->id,
            'poultry_vaccine_product_id' => $this->product->id,
            'quantity' => 100.50,
            'status' => 'available',
            'batch_number' => 'BATCH001',
            'unit_cost' => 25.00,
        ]);
    }

    /** @test */
    public function user_cannot_create_vaccine_inventory_with_invalid_data()
    {
        $invalidData = [
            'poultry_vaccine_product_id' => 99999, // Non-existent product
            'quantity' => -10, // Negative quantity
            'status' => 'invalid_status',
            'unit_cost' => -5.00, // Negative cost
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/farms/{$this->farm->id}/vaccine-inventory", $invalidData);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'message',
                'errors'
            ]);
    }

    /** @test */
    public function user_can_view_specific_vaccine_inventory()
    {
        $inventory = PoultryVaccineInventory::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_vaccine_product_id' => $this->product->id,
            'country_id' => $this->country->id,
            'created_by' => $this->user->id
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/vaccine-inventory/{$inventory->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'quantity',
                    'status',
                    'product',
                    'country',
                    'created_by'
                ],
                'message'
            ]);
    }

    /** @test */
    public function user_can_update_vaccine_inventory()
    {
        $inventory = PoultryVaccineInventory::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_vaccine_product_id' => $this->product->id,
            'country_id' => $this->country->id,
            'created_by' => $this->user->id
        ]);

        $updateData = [
            'quantity' => 75.25,
            'status' => 'in_use',
            'batch_number' => 'BATCH002',
            'unit_cost' => 30.00,
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/farms/{$this->farm->id}/vaccine-inventory/{$inventory->id}", $updateData);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'quantity',
                    'status',
                    'batch_number',
                    'unit_cost'
                ],
                'message'
            ]);

        $this->assertDatabaseHas('poultry_vaccine_inventories', [
            'id' => $inventory->id,
            'quantity' => 75.25,
            'status' => 'in_use',
            'batch_number' => 'BATCH002',
            'unit_cost' => 30.00,
        ]);
    }

    /** @test */
    public function user_can_delete_vaccine_inventory()
    {
        $inventory = PoultryVaccineInventory::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_vaccine_product_id' => $this->product->id,
            'country_id' => $this->country->id,
            'created_by' => $this->user->id
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/farms/{$this->farm->id}/vaccine-inventory/{$inventory->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
                'message'
            ]);

        $this->assertSoftDeleted('poultry_vaccine_inventories', [
            'id' => $inventory->id
        ]);
    }

    /** @test */
    public function user_can_view_vaccine_inventory_statistics()
    {
        // Create multiple inventory items
        PoultryVaccineInventory::factory()->count(3)->create([
            'farm_id' => $this->farm->id,
            'poultry_vaccine_product_id' => $this->product->id,
            'country_id' => $this->country->id,
            'created_by' => $this->user->id,
            'status' => 'available'
        ]);

        PoultryVaccineInventory::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_vaccine_product_id' => $this->product->id,
            'country_id' => $this->country->id,
            'created_by' => $this->user->id,
            'status' => 'depleted',
            'expiry_date' => now()->subDays(10) // Expired
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/vaccine-inventory/statistics");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_items',
                    'total_quantity',
                    'total_value',
                    'by_status',
                    'expired_items',
                    'expiring_soon',
                    'low_stock',
                    'by_product'
                ],
                'message'
            ]);
    }

    /** @test */
    public function user_can_view_vaccine_inventory_alerts()
    {
        // Create expired inventory
        PoultryVaccineInventory::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_vaccine_product_id' => $this->product->id,
            'country_id' => $this->country->id,
            'created_by' => $this->user->id,
            'expiry_date' => now()->subDays(5)
        ]);

        // Create expiring soon inventory
        PoultryVaccineInventory::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_vaccine_product_id' => $this->product->id,
            'country_id' => $this->country->id,
            'created_by' => $this->user->id,
            'expiry_date' => now()->addDays(15)
        ]);

        // Create low stock inventory
        PoultryVaccineInventory::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_vaccine_product_id' => $this->product->id,
            'country_id' => $this->country->id,
            'created_by' => $this->user->id,
            'quantity' => 5
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/vaccine-inventory/alerts");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'expired',
                    'expiring_soon',
                    'low_stock',
                    'depleted'
                ],
                'message'
            ]);
    }

    /** @test */
    public function user_can_view_available_products()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/vaccine-inventory/available-products");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'vaccine' => [
                            'id',
                            'name'
                        ],
                        'administrationMethod' => [
                            'id',
                            'name'
                        ]
                    ]
                ],
                'message'
            ]);
    }

    /** @test */
    public function user_can_view_countries()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/countries");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'code'
                    ]
                ],
                'message'
            ]);
    }

    /** @test */
    public function user_can_bulk_update_inventory_status()
    {
        $inventories = PoultryVaccineInventory::factory()->count(3)->create([
            'farm_id' => $this->farm->id,
            'poultry_vaccine_product_id' => $this->product->id,
            'country_id' => $this->country->id,
            'created_by' => $this->user->id,
            'status' => 'available'
        ]);

        $inventoryIds = $inventories->pluck('id')->toArray();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/farms/{$this->farm->id}/vaccine-inventory/bulk-update-status", [
                'inventory_ids' => $inventoryIds,
                'status' => 'in_use'
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'updated_count'
                ],
                'message'
            ]);

        foreach ($inventories as $inventory) {
            $this->assertDatabaseHas('poultry_vaccine_inventories', [
                'id' => $inventory->id,
                'status' => 'in_use'
            ]);
        }
    }

    /** @test */
    public function user_can_filter_inventory_by_status()
    {
        PoultryVaccineInventory::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_vaccine_product_id' => $this->product->id,
            'country_id' => $this->country->id,
            'created_by' => $this->user->id,
            'status' => 'available'
        ]);

        PoultryVaccineInventory::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_vaccine_product_id' => $this->product->id,
            'country_id' => $this->country->id,
            'created_by' => $this->user->id,
            'status' => 'depleted'
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/vaccine-inventory?status=available");

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('available', $data[0]['status']);
    }

    /** @test */
    public function user_can_search_inventory()
    {
        $inventory = PoultryVaccineInventory::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_vaccine_product_id' => $this->product->id,
            'country_id' => $this->country->id,
            'created_by' => $this->user->id,
            'batch_number' => 'SEARCHABLE_BATCH'
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/vaccine-inventory?search=SEARCHABLE");

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('SEARCHABLE_BATCH', $data[0]['batch_number']);
    }

    /** @test */
    public function user_cannot_access_inventory_from_different_farm()
    {
        $otherFarm = Farm::factory()->create();
        $inventory = PoultryVaccineInventory::factory()->create([
            'farm_id' => $otherFarm->id,
            'poultry_vaccine_product_id' => $this->product->id,
            'country_id' => $this->country->id,
            'created_by' => $this->user->id
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/vaccine-inventory/{$inventory->id}");

        $response->assertStatus(404);
    }

    /** @test */
    public function inventory_creation_logs_event()
    {
        $inventoryData = [
            'poultry_vaccine_product_id' => $this->product->id,
            'quantity' => 100.50,
            'status' => 'available',
            'batch_number' => 'BATCH001',
            'unit_cost' => 25.00,
            'country_id' => $this->country->id,
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/farms/{$this->farm->id}/vaccine-inventory", $inventoryData);

        $response->assertStatus(201);

        $this->assertDatabaseHas('poultry_events', [
            'farm_id' => $this->farm->id,
            'event_type' => 'vaccine_inventory_created',
            'table_name' => 'poultry_vaccine_inventories'
        ]);
    }

    /** @test */
    public function inventory_update_logs_event()
    {
        $inventory = PoultryVaccineInventory::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_vaccine_product_id' => $this->product->id,
            'country_id' => $this->country->id,
            'created_by' => $this->user->id
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/farms/{$this->farm->id}/vaccine-inventory/{$inventory->id}", [
                'quantity' => 50.00
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('poultry_events', [
            'farm_id' => $this->farm->id,
            'event_type' => 'vaccine_inventory_updated',
            'table_name' => 'poultry_vaccine_inventories',
            'table_id' => $inventory->id
        ]);
    }

    /** @test */
    public function inventory_deletion_logs_event()
    {
        $inventory = PoultryVaccineInventory::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_vaccine_product_id' => $this->product->id,
            'country_id' => $this->country->id,
            'created_by' => $this->user->id
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/farms/{$this->farm->id}/vaccine-inventory/{$inventory->id}");

        $response->assertStatus(200);

        $this->assertDatabaseHas('poultry_events', [
            'farm_id' => $this->farm->id,
            'event_type' => 'vaccine_inventory_deleted',
            'table_name' => 'poultry_vaccine_inventories',
            'table_id' => $inventory->id
        ]);
    }
} 