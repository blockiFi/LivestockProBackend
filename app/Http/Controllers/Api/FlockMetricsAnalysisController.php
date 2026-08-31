<?php

namespace App\Http\Controllers\Api;

use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockComparativeReport;
use App\Services\FarmEntitlementService;
use App\Services\FlockComparativeAnalysisService;
use App\Services\FlockMetricsAnalysisService;
use Spatie\Permission\PermissionRegistrar;

class FlockMetricsAnalysisController extends ApiController
{
    public function __construct(
        protected FlockMetricsAnalysisService $service,
        protected FlockComparativeAnalysisService $comparativeService
    ) {
    }

    public function aiInsights($farm, $flock)
    {
        $farm = Farm::findOrFail($farm);
        $flock = Flock::where('farm_id', $farm->id)->findOrFail($flock);

        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('view flocks', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view flocks', [], 403);
        }

        $snapshot = $this->service->buildSnapshot($flock);
        $result = $this->service->generateInsights($flock);

        $insights = $result['insights'];
        $analysis = $result['analysis'];
        $aiAvailable = $insights !== null || $analysis !== null;

        return $this->sendResponse([
            'metrics_snapshot' => $snapshot,
            'ai_insights' => $insights,
            'ai_analysis' => $analysis,
            'ai_available' => $aiAvailable,
        ], 'Flock metrics AI insights retrieved successfully');
    }

    public function comparative($farm, $flock)
    {
        $farm = Farm::findOrFail($farm);
        $flock = Flock::where('farm_id', $farm->id)->findOrFail($flock);

        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('view flocks', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view flocks', [], 403);
        }

        $report = $this->comparativeService->getOrGenerate(
            $flock,
            auth()->id(),
            false
        );

        return $this->sendResponse(
            $this->withAiEntitlement($farm, $report),
            'Flock comparative metrics retrieved successfully'
        );
    }

    public function refreshComparative($farm, $flock)
    {
        $farm = Farm::findOrFail($farm);
        $flock = Flock::where('farm_id', $farm->id)->findOrFail($flock);

        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('view flocks', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view flocks', [], 403);
        }

        $report = $this->comparativeService->getOrGenerate(
            $flock,
            auth()->id(),
            true
        );

        return $this->sendResponse(
            $this->withAiEntitlement($farm, $report),
            'Flock comparative metrics regenerated successfully'
        );
    }

    /**
     * Peer comparison numbers are available on every plan, but the AI-written
     * narrative is Premium-only.
     */
    protected function withAiEntitlement(Farm $farm, mixed $report): mixed
    {
        if (app(FarmEntitlementService::class)->canUseAi($farm)) {
            return $report;
        }

        if ($report instanceof FlockComparativeReport) {
            $report->ai_insights = null;
        } elseif (is_array($report) && array_key_exists('ai_insights', $report)) {
            $report['ai_insights'] = null;
        }

        return $report;
    }
}
