<?php

namespace App\Services\Admin;

use App\Models\PlatformSetting;

class PlatformSettingsService
{
    public const KEY_FARM_APP_URL = 'farm_app_url';

    public function getFarmAppUrl(): string
    {
        $stored = PlatformSetting::getValue(self::KEY_FARM_APP_URL);
        $url = null;

        if (is_array($stored)) {
            $url = $stored['url'] ?? null;
        } elseif (is_string($stored)) {
            $url = $stored;
        }

        if (is_string($url) && trim($url) !== '') {
            return rtrim(trim($url), '/');
        }

        $envUrl = env('FRONTEND_URL');
        if (is_string($envUrl) && trim($envUrl) !== '') {
            return rtrim(trim($envUrl), '/');
        }

        return 'http://localhost:5173';
    }

    public function setFarmAppUrl(string $url, ?int $updatedBy = null): void
    {
        PlatformSetting::setValue(
            self::KEY_FARM_APP_URL,
            ['url' => rtrim(trim($url), '/')],
            $updatedBy
        );
    }

    /**
     * @return array{farm_app_url: string}
     */
    public function getSupportSettings(): array
    {
        return [
            'farm_app_url' => $this->getFarmAppUrl(),
        ];
    }
}
