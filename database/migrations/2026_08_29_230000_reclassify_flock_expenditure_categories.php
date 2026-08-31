<?php

use App\Services\ExpenditureClassificationService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(ExpenditureClassificationService::class)->reclassifyAll(dryRun: false);
    }

    public function down(): void
    {
        // Reclassification is data-specific and not safely reversible.
    }
};
