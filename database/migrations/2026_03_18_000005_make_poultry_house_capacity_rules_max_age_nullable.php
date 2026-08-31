<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('poultry_house_capacity_rules')) {
            return;
        }

        // This migration uses `ALTER TABLE ... MODIFY ...` which is MySQL-specific.
        // SQLite (used in test environments) does not support it.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // MySQL: MODIFY is the most compatible way here (no doctrine/dbal required).
        // We keep the column unsigned because it was created as `unsignedInteger` previously.
        DB::statement('ALTER TABLE poultry_house_capacity_rules MODIFY max_age_days INT UNSIGNED NULL');
    }

    public function down(): void
    {
        if (!Schema::hasTable('poultry_house_capacity_rules')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Avoid failing the NOT NULL conversion when data contains NULLs.
        DB::statement('UPDATE poultry_house_capacity_rules SET max_age_days = 0 WHERE max_age_days IS NULL');
        DB::statement('ALTER TABLE poultry_house_capacity_rules MODIFY max_age_days INT UNSIGNED NOT NULL');
    }
};

