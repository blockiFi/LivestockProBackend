<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Services\Admin\AdminDashboardService;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends ApiController
{
    public function __construct(private readonly AdminDashboardService $service)
    {
    }

    public function index(): JsonResponse
    {
        return $this->sendResponse($this->service->getKpis(), 'Admin dashboard retrieved');
    }
}
