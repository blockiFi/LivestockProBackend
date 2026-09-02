<?php

namespace App\Http\Controllers\Api;

use App\Models\Farm;
use App\Models\Flock;
use App\Services\FlockActivityReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\PermissionRegistrar;

class FlockActivityReportController extends ApiController
{
    public function __construct(
        protected FlockActivityReportService $reportService
    ) {
    }

    public function index(Request $request, $farmId, $flockId)
    {
        $farm = Farm::findOrFail($farmId);
        $flock = Flock::where('farm_id', $farmId)->findOrFail($flockId);

        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (! $request->user()->can('view flocks', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view flocks', [], 403);
        }

        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'activity_type' => 'nullable|string|in:' . implode(',', FlockActivityReportService::CATEGORIES),
            'search' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $start = Carbon::parse($request->input('start_date'))->startOfDay();
        $end = Carbon::parse($request->input('end_date'))->startOfDay();

        if ($start->diffInDays($end) > 365) {
            return $this->sendValidationError('Validation failed', [
                'end_date' => ['Date range cannot exceed 365 days'],
            ]);
        }

        $report = $this->reportService->report(
            $farm,
            $flock,
            $start->toDateString(),
            $end->toDateString(),
            $request->input('activity_type'),
            $request->input('search'),
            (int) $request->input('page', 1),
            (int) $request->input('per_page', 25)
        );

        return $this->sendResponse($report, 'Flock activities retrieved successfully');
    }
}
