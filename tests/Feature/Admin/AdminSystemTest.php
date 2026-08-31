<?php

namespace Tests\Feature\Admin;

class AdminSystemTest extends AdminTestCase
{
    public function test_system_health(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/system/health');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['database', 'cache', 'queue', 'disk', 'app']]);
    }

    public function test_system_config(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/system/config');

        $response->assertStatus(200);
    }

    public function test_regular_user_cannot_access_system_health(): void
    {
        $response = $this->withHeaders($this->userHeaders())
            ->getJson('/api/admin/system/health');

        $response->assertStatus(403);
    }
}
