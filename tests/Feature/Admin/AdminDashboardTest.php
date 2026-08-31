<?php

namespace Tests\Feature\Admin;

class AdminDashboardTest extends AdminTestCase
{
    public function test_dashboard_returns_kpis(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'farms' => ['total', 'active', 'suspended'],
                    'users' => ['total', 'platform_admins'],
                    'flocks' => ['total', 'active'],
                    'birds' => ['total_active'],
                ],
            ]);
    }
}
