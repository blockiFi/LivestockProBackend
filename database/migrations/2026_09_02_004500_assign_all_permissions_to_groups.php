<?php

use App\Services\PermissionGroupAssignmentService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionGroupAssignmentService::class)->assignAll();
    }

    public function down(): void
    {
        // Group assignments are data fixes; no rollback.
    }
};
