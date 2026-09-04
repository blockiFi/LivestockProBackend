<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\User;
use App\Traits\ManagesFarmRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FarmOwnerPermissionsTest extends TestCase
{
    use RefreshDatabase;
    use ManagesFarmRoles;

    public function test_farm_creator_receives_all_poultry_owner_permissions(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;
        $country = Country::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/farms', [
                'name' => 'Poultry Owner Farm',
                'address' => '1 Farm Road',
                'country_id' => $country->id,
                'state' => 'Lagos',
                'city' => 'Ikeja',
            ]);

        $response->assertCreated();
        $farmId = $response->json('data.id');
        $this->assertNotNull($farmId);

        app(PermissionRegistrar::class)->setPermissionsTeamId($farmId);
        $user->refresh();
        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');

        $expectedPoultryPermissions = [
            'view flocks',
            'create flocks',
            'manage flocks',
            'view poultry houses',
            'manage poultry houses',
            'view poultry types',
            'manage poultry types',
            'view feed inventories',
            'create feed inventories',
            'manage feed inventory',
            'view feed products',
            'create feed products',
            'view feed usages',
            'create feed usages',
            'view flock egg reports',
            'create flock egg reports',
            'view flock weight reports',
            'create flock weight reports',
            'view flock mortality reports',
            'create flock mortality reports',
            'view medications',
            'create medications',
            'view vaccines',
            'create vaccines',
            'view vaccination records',
            'create vaccination records',
            'view schedules',
            'manage schedules',
            'view farm tasks',
            'manage farm tasks',
            'view sales',
            'create sales',
            'view customers',
            'manage customers',
        ];

        foreach ($expectedPoultryPermissions as $permission) {
            $this->assertTrue(
                $user->hasPermissionTo($permission, 'api'),
                "Owner should have poultry permission: {$permission}"
            );
        }

        $allFarmPermissions = $this->getAllFarmPermissions();
        $this->assertSame(
            count($allFarmPermissions),
            $user->getAllPermissions()->pluck('name')->unique()->count(),
            'Owner should receive the full farm permission set'
        );
    }
}
