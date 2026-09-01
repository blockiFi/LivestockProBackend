<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Services\Admin\AdminAuditService;
use App\Services\Admin\AdminFarmService;
use App\Services\FarmDashboardService;
use App\Services\FarmDeletionService;
use App\Traits\LogsAdminAction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminFarmController extends ApiController
{
    use LogsAdminAction;

    public function __construct(
        private readonly AdminFarmService $farmService,
        private readonly AdminAuditService $auditService,
        private readonly FarmDashboardService $dashboardService,
        private readonly FarmDeletionService $farmDeletionService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->sendResponse($this->farmService->list($request), 'Farms retrieved');
    }

    public function show(Farm $farm): JsonResponse
    {
        return $this->sendResponse($this->farmService->show($farm), 'Farm retrieved');
    }

    public function update(Request $request, Farm $farm): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'status' => 'sometimes',
            'email' => 'sometimes|nullable|email',
            'phone' => 'sometimes|nullable|string|max:20',
        ]);

        if (array_key_exists('status', $validated)) {
            $validated['status'] = $this->normalizeFarmStatus($validated['status']);
        }

        $old = $farm->only(array_keys($validated));
        $updated = $this->farmService->update($farm, $validated);

        $this->logAdminAction($request, 'farm.update', 'farm', $farm->id, $old, $validated);

        return $this->sendResponse($updated, 'Farm updated');
    }

    public function destroy(Request $request, int $farm): JsonResponse
    {
        $validated = $request->validate([
            'confirmation' => 'required|string',
        ]);

        $farmModel = Farm::withTrashed()->findOrFail($farm);

        if ($validated['confirmation'] !== $farmModel->name) {
            return $this->sendError('Farm name confirmation does not match', [], 422);
        }

        $farmId = $farmModel->id;
        $this->farmDeletionService->purge($farmModel);
        $this->logAdminAction($request, 'farm.purge', 'farm', $farmId);

        return $this->sendResponse(null, 'Farm and all related data deleted permanently');
    }

    public function restore(Request $request, int $farm): JsonResponse
    {
        $farmModel = Farm::withTrashed()->findOrFail($farm);
        $farmModel->restore();

        $this->logAdminAction($request, 'farm.restore', 'farm', $farmModel->id);

        return $this->sendResponse($farmModel, 'Farm restored');
    }

    public function statistics(Farm $farm): JsonResponse
    {
        [$start, $end] = $this->dashboardService->resolveDateRange('lifetime', null, null, $farm);
        $dashboard = $this->dashboardService->build($farm, $start, $end);

        return $this->sendResponse($dashboard, 'Farm statistics retrieved');
    }

    public function auditLog(Farm $farm, Request $request): JsonResponse
    {
        return $this->sendResponse(
            $this->auditService->forFarm($farm->id, $request->integer('per_page', 25)),
            'Farm audit log retrieved'
        );
    }

    private function normalizeFarmStatus(mixed $status): bool
    {
        if (is_bool($status)) {
            return $status;
        }

        if (is_numeric($status)) {
            return (bool) $status;
        }

        return match (strtolower((string) $status)) {
            'active', 'true', '1' => true,
            default => false,
        };
    }
}
