<?php

namespace Tests\Feature\Admin;

use App\Models\PlatformSetting;
use App\Services\Admin\PlatformSettingsService;

class AdminPlatformSettingsTest extends AdminTestCase
{
    public function test_support_admin_can_read_platform_settings(): void
    {
        PlatformSetting::setValue(PlatformSettingsService::KEY_FARM_APP_URL, [
            'url' => 'https://app.example.com',
        ], $this->admin->id);

        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/platform-settings');

        $response->assertStatus(200)
            ->assertJsonPath('data.farm_app_url', 'https://app.example.com');
    }

    public function test_support_admin_can_update_farm_app_url(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->putJson('/api/admin/platform-settings', [
                'farm_app_url' => 'https://livestockprofrontend.vercel.app',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.farm_app_url', 'https://livestockprofrontend.vercel.app');

        $this->assertDatabaseHas('platform_settings', [
            'key' => PlatformSettingsService::KEY_FARM_APP_URL,
        ]);
    }

    public function test_impersonation_response_includes_configured_farm_app_url(): void
    {
        app(PlatformSettingsService::class)->setFarmAppUrl(
            'https://farm-app.example.com',
            $this->admin->id
        );

        $response = $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/impersonate/{$this->regularUser->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.farm_app_url', 'https://farm-app.example.com');
    }

    public function test_regular_user_cannot_update_platform_settings(): void
    {
        $response = $this->withHeaders($this->userHeaders())
            ->putJson('/api/admin/platform-settings', [
                'farm_app_url' => 'https://app.example.com',
            ]);

        $response->assertStatus(403);
    }
}
