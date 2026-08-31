<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PermissionDoesNotExist as AppPermissionDoesNotExist;
use App\Models\Farm;
use App\Models\User;
use App\Services\FarmDashboardService;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\PermissionDoesNotExist as SpatiePermissionDoesNotExist;
use Spatie\Permission\PermissionRegistrar;

class DashboardController extends ApiController
{
    public function __construct(
        private readonly FarmDashboardService $dashboardService
    ) {
    }

    public function index(Request $request, $farm)
    {
        $farm = Farm::findOrFail($farm);
        $user = $request->user();

        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (! $this->safeCan($user, 'view statistics', $farm->id)) {
            return $this->sendUnauthorizedError('You do not have permission to view the farm dashboard');
        }

        try {
            [$startDate, $endDate] = $this->dashboardService->resolveDateRange(
                $request->get('preset'),
                $request->get('start_date'),
                $request->get('end_date'),
                $farm
            );
        } catch (\Throwable $e) {
            return $this->sendValidationError('Invalid date range', ['date' => [$e->getMessage()]]);
        }

        if ($startDate->gt($endDate)) {
            return $this->sendValidationError('Start date cannot be after end date');
        }

        $alertPermissions = [
            'view_feed' => $this->safeCanAny($user, [
                'view feed inventories',
                'view feed inventory',
            ], $farm->id),
            'view_medication' => $this->safeCanAny($user, [
                'view medication inventory',
                'view medication inventories',
            ], $farm->id),
            'view_vaccine' => $this->safeCanAny($user, [
                'view vaccine inventory',
                'view vaccine inventories',
            ], $farm->id),
        ];

        $alertPreferences = $this->resolveAlertPreferences($user);

        try {
            $payload = $this->dashboardService->build($farm, $startDate, $endDate, $alertPermissions, $alertPreferences);
        } catch (\Throwable $e) {
            report($e);

            return $this->sendError('Error building farm dashboard: '.$e->getMessage(), [], 500);
        }

        return $this->sendResponse($payload, 'Farm dashboard retrieved successfully');
    }

    private function safeCan(User $user, string $permission, int $farmId): bool
    {
        try {
            return (bool) $user->can($permission, 'api', $farmId);
        } catch (AppPermissionDoesNotExist|SpatiePermissionDoesNotExist $e) {
            return false;
        }
    }

    /**
     * @param  list<string>  $permissions
     */
    private function safeCanAny(User $user, array $permissions, int $farmId): bool
    {
        foreach ($permissions as $permission) {
            if ($this->safeCan($user, $permission, $farmId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{notify_low_stock: bool, notify_schedules: bool, notify_mortality: bool}
     */
    private function resolveAlertPreferences(User $user): array
    {
        $settings = $user->settingsOrDefault();

        return [
            'notify_low_stock' => (bool) ($settings->notify_low_stock ?? true),
            'notify_schedules' => (bool) ($settings->notify_schedules ?? true),
            'notify_mortality' => (bool) ($settings->notify_mortality ?? true),
        ];
    }
}
