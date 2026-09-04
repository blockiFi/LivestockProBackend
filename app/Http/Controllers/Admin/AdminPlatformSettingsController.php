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
        return $this->sendResponse(
            $this->settings->getSupportSettings(),
            'Platform settings retrieved'
        );
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'farm_app_url' => ['required', 'string', 'url', 'max:255'],
        ]);

        $this->settings->setFarmAppUrl($validated['farm_app_url'], $request->user()->id);

        $this->logAdminAction($request, 'platform_settings.update', 'platform_setting', null, null, [
            'farm_app_url' => $this->settings->getFarmAppUrl(),
        ]);

        return $this->sendResponse(
            $this->settings->getSupportSettings(),
            'Platform settings updated'
        );
    }
}
