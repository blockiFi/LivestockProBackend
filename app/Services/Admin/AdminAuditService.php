<?php

namespace App\Services\Admin;

use App\Models\AdminAuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class AdminAuditService
{
    public function list(Request $request): LengthAwarePaginator
    {
        $query = AdminAuditLog::with('adminUser:id,name,email')
            ->orderByDesc('created_at');

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('resource_type')) {
            $query->where('resource_type', $request->resource_type);
        }

        if ($request->filled('admin_user_id')) {
            $query->where('admin_user_id', $request->admin_user_id);
        }

        if ($request->filled('resource_id')) {
            $query->where('resource_id', $request->resource_id);
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        return $query->paginate($request->integer('per_page', 25));
    }

    public function forFarm(int $farmId, int $perPage = 25): LengthAwarePaginator
    {
        return AdminAuditLog::with('adminUser:id,name,email')
            ->where('resource_type', 'farm')
            ->where('resource_id', $farmId)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}
