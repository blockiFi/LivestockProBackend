<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\AdminAuditLog;
use App\Services\Admin\AdminAuditService;
use App\Support\CsvExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminAuditLogController extends ApiController
{
    public function __construct(private readonly AdminAuditService $auditService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->sendResponse($this->auditService->list($request), 'Audit logs retrieved');
    }

    public function facets(): JsonResponse
    {
        return $this->sendResponse($this->auditService->facets(), 'Audit log filters retrieved');
    }

    public function export(Request $request): StreamedResponse
    {
        return CsvExport::stream($this->auditService->query($request), [
            'Time (UTC)' => fn (AdminAuditLog $l) => $l->created_at,
            'Admin' => fn (AdminAuditLog $l) => $l->adminUser?->email,
            'Action' => fn (AdminAuditLog $l) => $l->action,
            'Resource type' => fn (AdminAuditLog $l) => $l->resource_type,
            'Resource ID' => fn (AdminAuditLog $l) => $l->resource_id,
            'Before' => fn (AdminAuditLog $l) => $l->old_values ? json_encode($l->old_values) : '',
            'After' => fn (AdminAuditLog $l) => $l->new_values ? json_encode($l->new_values) : '',
            'IP address' => fn (AdminAuditLog $l) => $l->ip_address,
        ], 'audit-log-' . now()->format('Y-m-d') . '.csv');
    }
}
