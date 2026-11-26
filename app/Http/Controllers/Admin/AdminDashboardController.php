<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Services\Admin\AdminDashboardService;
use App\Services\Admin\AdminInsightsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDashboardController extends ApiController
{
    public function __construct(
        private readonly AdminDashboardService $service,
        private readonly AdminInsightsService $insights,
    ) {
    }

    public function index(): JsonResponse
    {
        return $this->sendResponse($this->service->getKpis(), 'Admin dashboard retrieved');
    }

    /** Trends, daily series, attention items and recent activity. */
    public function insights(Request $request): JsonResponse
    {
        return $this->sendResponse($this->insights->dashboard($request->integer('days', 30)), 'Dashboard insights retrieved');
    }

    /** Items for the header bell: what needs an admin's attention now. */
    public function alerts(): JsonResponse
    {
        return $this->sendResponse(['items' => $this->insights->attention()], 'Alerts retrieved');
    }
}
