<?php

namespace App\Services\Admin;

use App\Models\AdminAuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AdminAuditService
{
    public function query(Request $request): Builder
    {
        $query = AdminAuditLog::with('adminUser:id,name,email')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->filled('action')) {
            // "farm.*" style prefixes match a whole family of actions.
            str_ends_with($request->action, '.*')
                ? $query->where('action', 'like', substr($request->action, 0, -1) . '%')
                : $query->where('action', $request->action);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($q) => $q->where('action', 'like', "%{$search}%")
                ->orWhere('ip_address', 'like', "%{$search}%")
                ->orWhereHas('adminUser', fn ($u) => $u->where('email', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")));
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

        return $query;
    }

    public function list(Request $request): LengthAwarePaginator
    {
        return $this->query($request)->paginate(min(100, max(5, $request->integer('per_page', 25))));
    }

    /** Values for the audit log filter menus. */
    public function facets(): array
    {
        return [
            'actions' => AdminAuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'resource_types' => AdminAuditLog::query()->whereNotNull('resource_type')->distinct()->orderBy('resource_type')->pluck('resource_type'),
            'admins' => \App\Models\User::whereIn('id', AdminAuditLog::query()->select('admin_user_id')->distinct())
                ->orderBy('name')->get(['id', 'name', 'email']),
        ];
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
