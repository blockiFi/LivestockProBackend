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
use App\Support\CsvExport;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function export(Request $request): StreamedResponse
    {
        $this->logAdminAction($request, 'farm.export', 'farm', null, null, $request->only(['search', 'status', 'subscription_status', 'plan_slug']));

        return CsvExport::stream($this->farmService->query($request), [
            'ID' => fn (Farm $f) => $f->id,
            'Name' => fn (Farm $f) => $f->name,
            'Owner' => fn (Farm $f) => $f->owner?->name,
            'Owner email' => fn (Farm $f) => $f->owner?->email,
            'Farm email' => fn (Farm $f) => $f->email,
            'Phone' => fn (Farm $f) => $f->phone,
            'Country' => fn (Farm $f) => $f->country?->name,
            'Status' => fn (Farm $f) => $f->deleted_at ? 'Deleted' : ($f->status ? 'Active' : 'Suspended'),
            'Plan' => fn (Farm $f) => $f->subscription?->plan?->name,
            'Subscription' => fn (Farm $f) => $f->subscription?->status,
            'Users' => fn (Farm $f) => $f->users_count,
            'Flocks' => fn (Farm $f) => $f->flocks_count,
            'Houses' => fn (Farm $f) => $f->poultry_houses_count,
            'Created' => fn (Farm $f) => $f->created_at,
        ], 'farms-' . now()->format('Y-m-d') . '.csv');
    }

    public function bulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['suspend', 'activate'])],
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'integer|exists:farms,id',
        ]);

        $status = $validated['action'] === 'activate';
        $ids = Farm::whereIn('id', $validated['ids'])->where('status', '!=', $status)->pluck('id')->all();
        Farm::whereIn('id', $ids)->update(['status' => $status]);

        $this->logAdminAction($request, "farm.bulk.{$validated['action']}", 'farm', null, null, ['ids' => $ids]);

        return $this->sendResponse(['updated' => count($ids), 'ids' => $ids], $status ? 'Farms activated' : 'Farms suspended');
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
