<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Services\Admin\AdminAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAuditLogController extends ApiController
{
    public function __construct(private readonly AdminAuditService $auditService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->sendResponse($this->auditService->list($request), 'Audit logs retrieved');
    }
}
