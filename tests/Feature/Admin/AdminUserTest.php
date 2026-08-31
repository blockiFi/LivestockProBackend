<?php

namespace Tests\Feature\Admin;

use App\Models\User;

class AdminUserTest extends AdminTestCase
{
    public function test_admin_can_list_users(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/users');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_admin_can_view_user_detail(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->getJson("/api/admin/users/{$this->regularUser->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.user.id', $this->regularUser->id);
    }

    public function test_admin_can_revoke_user_tokens(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->deleteJson("/api/admin/users/{$this->regularUser->id}/tokens");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $this->regularUser->id,
            'tokenable_type' => User::class,
        ]);
    }

    public function test_admin_can_toggle_platform_admin_flag(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->putJson("/api/admin/users/{$this->regularUser->id}", [
                'is_platform_admin' => true,
                'platform_admin_role' => 'analyst',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $this->regularUser->id,
            'is_platform_admin' => true,
            'platform_admin_role' => 'analyst',
        ]);
    }
}
