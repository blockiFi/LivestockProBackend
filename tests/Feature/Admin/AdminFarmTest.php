<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;

class AdminFarmTest extends AdminTestCase
{
    public function test_admin_can_list_farms(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/farms');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_admin_can_view_farm_detail(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->getJson("/api/admin/farms/{$this->farm->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.farm.id', $this->farm->id);
    }

    public function test_admin_can_suspend_farm(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->putJson("/api/admin/farms/{$this->farm->id}", ['status' => false]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('farms', ['id' => $this->farm->id, 'status' => 0]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $this->admin->id,
            'action' => 'farm.update',
            'resource_type' => 'farm',
            'resource_id' => $this->farm->id,
        ]);
    }

    public function test_suspended_farm_blocked_from_farm_routes(): void
    {
        $this->farm->update(['status' => false]);

        $response = $this->withHeaders($this->userHeaders())
            ->getJson("/api/farms/{$this->farm->id}");

        $response->assertStatus(403);
    }

    public function test_admin_can_delete_farm(): void
    {
        $farmId = $this->farm->id;
        $farmName = $this->farm->name;

        $response = $this->withHeaders($this->adminHeaders())
            ->deleteJson("/api/admin/farms/{$farmId}", ['confirmation' => $farmName]);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('farms', ['id' => $farmId]);
        $this->assertDatabaseMissing('farm_users', ['farm_id' => $farmId]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $this->admin->id,
            'action' => 'farm.purge',
            'resource_type' => 'farm',
            'resource_id' => $farmId,
        ]);
    }

    public function test_admin_farm_delete_requires_name_confirmation(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->deleteJson("/api/admin/farms/{$this->farm->id}", ['confirmation' => 'wrong-name']);

        $response->assertStatus(422);
        $this->assertDatabaseHas('farms', ['id' => $this->farm->id]);
    }
}
