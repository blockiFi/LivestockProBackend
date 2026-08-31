<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Set type based on farm_id
        DB::statement("UPDATE schedules SET type = 'user' WHERE farm_id IS NOT NULL AND (type IS NULL OR type = '')");
        DB::statement("UPDATE schedules SET type = 'default' WHERE farm_id IS NULL AND (type IS NULL OR type = '')");

        // Backfill schedule_type from items
        // SQLite doesn't support UPDATE..JOIN, so use EXISTS subqueries.
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            DB::statement("UPDATE schedules
                SET schedule_type = 'medication'
                WHERE schedule_type IS NULL
                AND EXISTS (
                    SELECT 1 FROM schedule_items si
                    WHERE si.schedule_id = schedules.id
                    AND si.poultry_medication_id IS NOT NULL
                )");
            DB::statement("UPDATE schedules
                SET schedule_type = 'vaccination'
                WHERE schedule_type IS NULL
                AND EXISTS (
                    SELECT 1 FROM schedule_items si
                    WHERE si.schedule_id = schedules.id
                    AND si.poultry_vaccine_id IS NOT NULL
                )");
        } else {
            // MySQL/MariaDB
            DB::statement("UPDATE schedules s
                JOIN schedule_items si ON si.schedule_id = s.id
                SET s.schedule_type = 'medication'
                WHERE s.schedule_type IS NULL AND si.poultry_medication_id IS NOT NULL");
            DB::statement("UPDATE schedules s
                JOIN schedule_items si ON si.schedule_id = s.id
                SET s.schedule_type = 'vaccination'
                WHERE s.schedule_type IS NULL AND si.poultry_vaccine_id IS NOT NULL");
        }
        // For any remaining nulls, default to medication
        DB::statement("UPDATE schedules SET schedule_type = 'medication' WHERE schedule_type IS NULL");
    }

    public function down(): void
    {
        // No-op rollback: we won't unset data. If needed, set schedule_type to NULL where no items exist.
    }
};
