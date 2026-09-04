<?php

use App\Services\FarmPermissionSyncService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(FarmPermissionSyncService::class)->syncAll();
    }

    public function down(): void
    {
        // Permissions are additive; no rollback.
    }
};
