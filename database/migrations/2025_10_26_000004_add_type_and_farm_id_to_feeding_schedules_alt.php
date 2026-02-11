<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feeding_schedules', function (Blueprint $table) {
            if (!Schema::hasColumn('feeding_schedules', 'type')) {
                $table->enum('type', ['default', 'user'])->default('default')->after('end_date');
            }
            if (!Schema::hasColumn('feeding_schedules', 'farm_id')) {
                $table->foreignId('farm_id')->nullable()->after('type')->constrained()->onDelete('cascade');
            }
        });
    }

    public function down(): void
    {
        Schema::table('feeding_schedules', function (Blueprint $table) {
            if (Schema::hasColumn('feeding_schedules', 'farm_id')) {
                // Use raw SQL to drop foreign key if it exists
                $tableName = 'feeding_schedules';
                $foreignKeyName = 'feeding_schedules_farm_id_foreign';
                
                // Check if constraint exists
                $constraintExists = DB::select(
                    "SELECT CONSTRAINT_NAME 
                     FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                     WHERE TABLE_SCHEMA = DATABASE() 
                     AND TABLE_NAME = ? 
                     AND CONSTRAINT_NAME = ?",
                    [$tableName, $foreignKeyName]
                );
                
                if (!empty($constraintExists)) {
                    DB::statement("ALTER TABLE {$tableName} DROP FOREIGN KEY {$foreignKeyName}");
                }
                
                $table->dropColumn('farm_id');
            }
            if (Schema::hasColumn('feeding_schedules', 'type')) {
                $table->dropColumn('type');
            }
        });
    }
};
