<?php

namespace App\Services\Admin;

use App\Models\PlatformSetting;

class PlatformSettingsService
{
    public const KEY_FARM_APP_URL = 'farm_app_url';
    public const KEY_ADMIN_SECURITY = 'admin_security';

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

    /**
     * Admin sign-in security: whether the email code is required and how long
     * an admin session lasts. Defaults come from config/admin.php.
     *
     * @return array{otp_required: bool, session_hours: int}
     */
    public function getAdminSecurity(): array
    {
        $stored = PlatformSetting::getValue(self::KEY_ADMIN_SECURITY);
        $stored = is_array($stored) ? $stored : [];

        return [
            'otp_required' => (bool) ($stored['otp_required'] ?? config('admin.otp_required', true)),
            'session_hours' => max(1, min(24, (int) ($stored['session_hours'] ?? config('admin.session_hours', 8)))),
        ];
    }

    /**
     * @param array{otp_required?: bool, session_hours?: int} $values
     */
    public function setAdminSecurity(array $values, ?int $updatedBy = null): array
    {
        $merged = array_merge($this->getAdminSecurity(), array_intersect_key($values, array_flip(['otp_required', 'session_hours'])));
        PlatformSetting::setValue(self::KEY_ADMIN_SECURITY, $merged, $updatedBy);

        return $this->getAdminSecurity();
    }
}
