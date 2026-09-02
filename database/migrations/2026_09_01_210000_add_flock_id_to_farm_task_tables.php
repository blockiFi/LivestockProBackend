<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farm_task_schedules', function (Blueprint $table) {
            $table->foreignId('flock_id')->nullable()->after('farm_id')->constrained('flocks')->nullOnDelete();
            $table->index(['farm_id', 'flock_id']);
        });

        Schema::table('farm_task_instances', function (Blueprint $table) {
            $table->foreignId('flock_id')->nullable()->after('farm_id')->constrained('flocks')->nullOnDelete();
            $table->index(['farm_id', 'flock_id', 'scheduled_date'], 'fti_farm_flock_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('farm_task_instances', function (Blueprint $table) {
            $table->dropIndex('fti_farm_flock_date_idx');
            $table->dropConstrainedForeignId('flock_id');
        });

        Schema::table('farm_task_schedules', function (Blueprint $table) {
            $table->dropIndex(['farm_id', 'flock_id']);
            $table->dropConstrainedForeignId('flock_id');
        });
    }
};
