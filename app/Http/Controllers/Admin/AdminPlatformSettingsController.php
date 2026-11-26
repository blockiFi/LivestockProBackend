<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Services\Admin\PlatformSettingsService;
use App\Traits\LogsAdminAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPlatformSettingsController extends ApiController
{
    use LogsAdminAction;

    public function __construct(private readonly PlatformSettingsService $settings)
    {
    }

    public function show(): JsonResponse
    {
        return $this->sendResponse($this->payload(), 'Platform settings retrieved');
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'farm_app_url' => ['sometimes', 'required', 'string', 'url', 'max:255'],
            'admin_security' => ['sometimes', 'array'],
            'admin_security.otp_required' => ['sometimes', 'boolean'],
            'admin_security.session_hours' => ['sometimes', 'integer', 'min:1', 'max:24'],
        ]);

        if (isset($validated['admin_security']) && $request->user()->platform_admin_role !== 'super_admin') {
            return $this->sendError('Only super admins can change admin security settings', [], 403);
        }

        $before = $this->payload();

        if (isset($validated['farm_app_url'])) {
            $this->settings->setFarmAppUrl($validated['farm_app_url'], $request->user()->id);
        }
        if (isset($validated['admin_security'])) {
            $this->settings->setAdminSecurity($validated['admin_security'], $request->user()->id);
        }

        $after = $this->payload();
        $this->logAdminAction($request, 'platform_settings.update', 'platform_setting', null, $before, $after);

        return $this->sendResponse($after, 'Platform settings updated');
    }

    private function payload(): array
    {
        return $this->settings->getSupportSettings() + [
            'admin_security' => $this->settings->getAdminSecurity(),
        ];
    }
}
