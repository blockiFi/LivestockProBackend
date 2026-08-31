<?php

namespace Tests\Feature\Admin;

use App\Models\Country;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class AdminTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $regularUser;
    protected string $adminToken;
    protected string $userToken;
    protected Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'is_platform_admin' => true,
            'platform_admin_role' => 'super_admin',
        ]);
        $this->adminToken = $this->admin->createToken('admin-test')->plainTextToken;

        $this->regularUser = User::factory()->create([
            'is_platform_admin' => false,
        ]);
        $this->userToken = $this->regularUser->createToken('user-test')->plainTextToken;

        $country = Country::factory()->create();
        $this->farm = Farm::factory()->create([
            'created_by' => $this->regularUser->id,
            'country_id' => $country->id,
            'status' => true,
        ]);
        $this->farm->users()->attach($this->regularUser->id);
    }

    protected function adminHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->adminToken];
    }

    protected function userHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->userToken];
    }
}
