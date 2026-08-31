<?php

namespace Tests\Feature\Admin;

class AdminOperationsTest extends AdminTestCase
{
    public function test_operations_overview(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/analytics/operations');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'period',
                    'schedules',
                    'feeds',
                    'financial',
                ],
            ]);
    }

    public function test_operations_overview_with_date_filters(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/analytics/operations?from=2025-01-01&to=2025-12-31');

        $response->assertStatus(200)
            ->assertJsonPath('data.period.from', '2025-01-01')
            ->assertJsonPath('data.period.to', '2025-12-31');
    }

    public function test_feed_analytics_with_date_filters(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/analytics/feeds?from=2025-01-01&to=2025-12-31');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'period' => ['from', 'to'],
                    'summary',
                    'top_formulated_products',
                ],
            ]);
    }

    public function test_schedule_analytics(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/analytics/schedules');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'summary' => [
                        'feeding_schedules',
                        'task_schedules',
                        'task_instances_overdue',
                    ],
                    'farms',
                ],
            ]);
    }

    public function test_feed_analytics(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/analytics/feeds');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'summary' => [
                        'feed_products',
                        'feed_components',
                        'feed_compositions',
                    ],
                    'usage_by_type',
                ],
            ]);
    }

    public function test_financial_analytics(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/analytics/financial');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'summary' => [
                        'total_revenue',
                        'total_cost',
                        'net_profit',
                    ],
                    'farm_rankings',
                ],
            ]);
    }

    public function test_extended_data_resources(): void
    {
        foreach (['feeding-schedules', 'feed-products', 'feed-components', 'task-schedules'] as $resource) {
            $response = $this->withHeaders($this->adminHeaders())
                ->getJson("/api/admin/{$resource}");

            $response->assertStatus(200);
        }
    }

    public function test_resource_show_endpoint(): void
    {
        $list = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/flocks');

        $list->assertStatus(200);
        $firstId = $list->json('data.data.0.id');

        if (! $firstId) {
            $this->markTestSkipped('No flock records available for show endpoint test.');
        }

        $show = $this->withHeaders($this->adminHeaders())
            ->getJson("/api/admin/flocks/{$firstId}");

        $show->assertStatus(200)
            ->assertJsonPath('data.id', $firstId);
    }
}
