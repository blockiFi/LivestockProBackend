<?php

namespace App\Traits;

use App\Models\AdminAuditLog;
use Illuminate\Http\Request;

trait LogsAdminAction
{
    protected function logAdminAction(
        Request $request,
        string $action,
        ?string $resourceType = null,
        ?int $resourceId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): AdminAuditLog {
        return AdminAuditLog::create([
            'admin_user_id' => $request->user()->id,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }
}
