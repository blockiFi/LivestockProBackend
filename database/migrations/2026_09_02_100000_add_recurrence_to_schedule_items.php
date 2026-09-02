<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_items', function (Blueprint $table) {
            $table->boolean('is_recurring')->default(false)->after('age_days');
            $table->unsignedInteger('interval_days')->nullable()->after('is_recurring');
        });

        Schema::table('batch_schedule_items', function (Blueprint $table) {
            $table->unique(
                ['batch_schedule_id', 'schedule_item_id', 'scheduled_date'],
                'batch_schedule_items_occurrence_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('batch_schedule_items', function (Blueprint $table) {
            $table->dropUnique('batch_schedule_items_occurrence_unique');
        });

        Schema::table('schedule_items', function (Blueprint $table) {
            $table->dropColumn(['is_recurring', 'interval_days']);
        });
    }
};
