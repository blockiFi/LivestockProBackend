<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\Flock;
use App\Services\SalesProfitLossService;
use Illuminate\Http\Request;

class SalesStatisticsController extends ApiController
{
    public function __construct(
        private readonly SalesProfitLossService $profitLossService
    ) {
    }

    public function flockProfitLoss(Request $request, $farmId, $flockId)
    {
        $farm = Farm::findOrFail($farmId);
        Flock::where('farm_id', $farmId)->findOrFail($flockId);

        if (! $request->user()->hasPermissionTo('view flocks', 'api', $farm)
            && ! $request->user()->hasPermissionTo('view sales', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view flock profit and loss');
        }

        $summary = $this->profitLossService->flockSummary(
            (int) $farmId,
            (int) $flockId,
            $request->input('date_from'),
            $request->input('date_to')
        );

        return $this->sendResponse($summary, 'Flock profit and loss retrieved successfully');
    }

    public function farmProfitLoss(Request $request, $farmId)
    {
        $farm = Farm::findOrFail($farmId);

        if (! $request->user()->hasPermissionTo('view flocks', 'api', $farm)
            && ! $request->user()->hasPermissionTo('view sales', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view farm sales statistics');
        }

        $dateFrom = $request->input('date_from', now()->subDays(30)->toDateString());
        $dateTo = $request->input('date_to', now()->toDateString());

        $summary = $this->profitLossService->farmSummary((int) $farmId, $dateFrom, $dateTo);

        return $this->sendResponse($summary, 'Farm sales profit and loss retrieved successfully');
    }
}
