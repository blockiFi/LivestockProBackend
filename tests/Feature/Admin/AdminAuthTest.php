<?php

namespace Tests\Feature\Admin;

class AdminAuthTest extends AdminTestCase
{
    public function test_non_admin_cannot_access_admin_routes(): void
    {
        $response = $this->withHeaders($this->userHeaders())
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(403);
    }

    public function test_unauthenticated_cannot_access_admin_routes(): void
    {
        $response = $this->getJson('/api/admin/dashboard');

        $response->assertStatus(401);
    }

    public function test_admin_can_access_dashboard(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'farms',
                    'users',
                    'flocks',
                    'birds',
                ],
            ]);
    }

    public function test_admin_health_endpoint_is_public_within_admin_group(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/health');

        $response->assertStatus(200);
    }
}
