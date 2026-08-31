<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Services\Admin\AdminInventoryAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminInventoryAlertController extends ApiController
{
    public function __construct(private readonly AdminInventoryAlertService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->sendResponse(
            $this->service->list($request->integer('farm_id') ?: null),
            'Inventory alerts retrieved'
        );
    }

    public function summary(Request $request): JsonResponse
    {
        return $this->sendResponse(
            $this->service->summary($request->integer('farm_id') ?: null),
            'Inventory alert summary retrieved'
        );
    }
}
