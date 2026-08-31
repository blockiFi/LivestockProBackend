<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\Admin\AdminImpersonationService;

class AdminImpersonationTest extends AdminTestCase
{
    public function test_admin_can_create_impersonation_token(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/impersonate/{$this->regularUser->id}");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['token', 'expires_at', 'user']]);

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $this->admin->id,
            'action' => 'user.impersonate',
        ]);
    }

    public function test_impersonation_token_authenticates_target_user(): void
    {
        $result = app(AdminImpersonationService::class)->createToken($this->admin, $this->regularUser);
        $token = $result['token'];

        $this->getJson('/api/user', [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.id', $this->regularUser->id)
            ->assertJsonPath('data.email', $this->regularUser->email)
            ->assertJsonPath('data.impersonation.active', true)
            ->assertJsonPath('data.impersonation.impersonated_by.email', $this->admin->email);
    }

    public function test_cannot_impersonate_platform_admin(): void
    {
        $otherAdmin = User::factory()->create([
            'is_platform_admin' => true,
            'platform_admin_role' => 'support',
        ]);

        $response = $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/impersonate/{$otherAdmin->id}");

        $response->assertStatus(403);
    }
}
