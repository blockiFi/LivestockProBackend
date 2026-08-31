<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Farm;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Equipment\EquipmentAssetIdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipmentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $country = Country::factory()->create();
        $this->farm = Farm::factory()->create([
            'created_by' => $this->user->id,
            'country_id' => $country->id,
        ]);

        $permissions = collect([
            'view equipment',
            'manage equipment',
            'view equipment financials',
        ])->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']));

        $role = Role::create([
            'name' => 'owner',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $role->givePermissionTo($permissions);

        $this->farm->users()->attach($this->user->id);
        $this->user->assignRole($role);
    }

    public function test_asset_id_is_generated_in_expected_format(): void
    {
        $service = app(EquipmentAssetIdService::class);
        $assetId = $service->nextAssetId($this->farm);

        $this->assertMatchesRegularExpression('/^EQP-\d{4}-\d{5}$/', $assetId);
    }

    public function test_can_create_and_list_equipment(): void
    {
        $category = EquipmentCategory::create([
            'farm_id' => null,
            'name' => 'Generators',
            'slug' => 'generators',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/farms/{$this->farm->id}/equipment", [
                'name' => 'Diesel Generator',
                'category_id' => $category->id,
                'brand' => 'Honda',
                'purchase_price' => 850000,
                'purchase_date' => '2026-08-01',
                'status' => 'available',
                'condition' => 'good',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Diesel Generator');

        $assetId = $response->json('data.asset_id');
        $this->assertNotEmpty($assetId);

        $list = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/equipment?search={$assetId}");

        $list->assertOk();
        $this->assertGreaterThanOrEqual(1, count($list->json('data.data') ?? $list->json('data')));
    }

    public function test_dashboard_returns_stats(): void
    {
        $category = EquipmentCategory::firstOrCreate(
            ['farm_id' => null, 'slug' => 'tools'],
            ['name' => 'Tools', 'is_active' => true]
        );

        Equipment::create([
            'farm_id' => $this->farm->id,
            'category_id' => $category->id,
            'asset_id' => 'EQP-2026-00001',
            'name' => 'Hammer',
            'status' => 'available',
            'condition' => 'good',
            'purchase_price' => 5000,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/equipment/dashboard");

        $response->assertOk()
            ->assertJsonPath('data.stats.total', 1)
            ->assertJsonPath('data.stats.total_purchase_value', 5000);
    }

    public function test_maintenance_log_updates_equipment(): void
    {
        $equipment = Equipment::create([
            'farm_id' => $this->farm->id,
            'asset_id' => 'EQP-2026-00099',
            'name' => 'Feed Mixer',
            'status' => 'under_maintenance',
            'condition' => 'good',
            'total_maintenance_cost' => 0,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/farms/{$this->farm->id}/equipment/{$equipment->id}/maintenance", [
                'title' => 'Belt replacement',
                'performed_at' => '2026-08-26',
                'total_cost' => 25000,
                'next_due_at' => '2026-11-26',
            ]);

        $response->assertCreated();

        $equipment->refresh();
        $this->assertEquals(25000, (float) $equipment->total_maintenance_cost);
        $this->assertEquals('2026-11-26', $equipment->next_maintenance_date?->format('Y-m-d'));
    }
}
