<?php

namespace Tests\Feature;

use App\Models\AdministrationMethod;
use App\Models\Country;
use App\Models\Farm;
use App\Models\Permission;
use App\Models\PoultryVaccine;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VaccineProductTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private string $token;
    private PoultryVaccine $vaccine;
    private AdministrationMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test')->plainTextToken;
        $country = Country::factory()->create();
        $this->farm = Farm::factory()->create([
            'created_by' => $this->user->id,
            'country_id' => $country->id,
        ]);

        $permissions = [
            'view vaccine products',
            'create vaccine products',
            'update vaccine products',
            'delete vaccine products',
            'view vaccines',
            'create vaccines',
            'view vaccine inventory',
            'manage vaccine inventory',
        ];

        foreach ($permissions as $permission) {
            Permission::create([
                'name' => $permission,
                'guard_name' => 'api',
                'farm_id' => $this->farm->id,
            ]);
        }

        $ownerRole = Role::create([
            'name' => 'owner',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);

        $ownerRole->givePermissionTo($permissions);

        $this->farm->users()->attach($this->user->id);
        $this->user->assignRole($ownerRole);

        $this->vaccine = PoultryVaccine::create([
            'name' => 'Newcastle',
            'description' => 'Test vaccine',
            'administration_age' => 7,
            'type' => 'default',
            'farm_id' => null,
        ]);
        $this->method = AdministrationMethod::create([
            'name' => 'Drinking Water',
            'description' => 'Via drinking water',
        ]);
    }

    public function test_farm_can_create_vaccine_product(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/vaccine-products", [
                'poultry_vaccine_id' => $this->vaccine->id,
                'name' => 'Farm Lasota Live',
                'manufacturer' => 'Zoetis',
                'administration_method_id' => $this->method->id,
                'dosage' => 0.5,
                'dosage_unit' => 'ml',
                'min_stock_level' => 10,
                'type' => 'user',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Farm Lasota Live')
            ->assertJsonPath('data.farm_id', $this->farm->id)
            ->assertJsonPath('data.type', 'user');
    }

    public function test_vaccine_data_endpoint_scopes_products_to_farm(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/vaccine-products", [
                'poultry_vaccine_id' => $this->vaccine->id,
                'name' => 'Scoped Product',
                'manufacturer' => 'Local',
                'administration_method_id' => $this->method->id,
                'type' => 'user',
            ])
            ->assertStatus(201);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/vaccines/data");

        $response->assertStatus(200);
        $products = collect($response->json('data'))
            ->flatMap(fn ($vaccine) => $vaccine['products'] ?? [])
            ->pluck('name');

        $this->assertTrue($products->contains('Scoped Product'));
    }

    public function test_vaccine_list_accessible_with_vaccine_product_permissions_only(): void
    {
        $limitedUser = User::factory()->create();
        $limitedToken = $limitedUser->createToken('test')->plainTextToken;

        $permissionNames = [
            'view vaccine products',
            'create vaccine products',
            'manage vaccine inventory',
        ];

        $role = Role::create([
            'name' => 'health_staff',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $role->givePermissionTo($permissionNames);

        $this->farm->users()->attach($limitedUser->id);
        $limitedUser->assignRole($role);

        $this->withHeader('Authorization', 'Bearer ' . $limitedToken)
            ->getJson("/api/farms/{$this->farm->id}/vaccines")
            ->assertStatus(200)
            ->assertJsonFragment(['name' => 'Newcastle']);

        $this->withHeader('Authorization', 'Bearer ' . $limitedToken)
            ->getJson("/api/farms/{$this->farm->id}/vaccines/data")
            ->assertStatus(200)
            ->assertJsonFragment(['name' => 'Newcastle']);
    }
}
