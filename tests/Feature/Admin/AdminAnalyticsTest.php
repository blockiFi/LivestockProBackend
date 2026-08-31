<?php

namespace Tests\Feature\Admin;

class AdminAnalyticsTest extends AdminTestCase
{
    public function test_growth_analytics(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/analytics/growth');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['user_signups', 'farm_creations', 'period']]);
    }

    public function test_usage_analytics(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/analytics/usage');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['ai_imports', 'feature_totals']]);
    }

    public function test_health_analytics(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/analytics/health');

        $response->assertStatus(200);
    }
}
