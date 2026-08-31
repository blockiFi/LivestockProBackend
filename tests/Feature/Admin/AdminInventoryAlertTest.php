<?php

namespace Tests\Feature\Admin;

class AdminInventoryAlertTest extends AdminTestCase
{
    public function test_inventory_alerts_endpoint(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/inventory-alerts');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['alerts', 'summary']]);
    }

    public function test_inventory_alerts_summary(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/inventory-alerts/summary');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['total']]);
    }
}
