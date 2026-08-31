<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feeding_schedule_items', function (Blueprint $table) {
            $table->unsignedInteger('start_day')->nullable()->after('quantity');
            $table->unsignedInteger('end_day')->nullable()->after('start_day');
            $table->index(['feeding_schedule_id', 'start_day'], 'feeding_schedule_items_schedule_start_day_idx');
        });

        // Backfill from legacy feeding_day (single-day rows become 1-day ranges).
        DB::table('feeding_schedule_items')
            ->whereNull('start_day')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $day = max(1, (int) ($row->feeding_day ?? 1));
                    DB::table('feeding_schedule_items')
                        ->where('id', $row->id)
                        ->update([
                            'start_day' => $day,
                            'end_day' => $day,
                        ]);
                }
            });

        Schema::table('feeding_schedule_items', function (Blueprint $table) {
            $table->unsignedInteger('start_day')->nullable(false)->change();
            $table->integer('feeding_day')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('feeding_schedule_items')
            ->whereNull('feeding_day')
            ->update(['feeding_day' => DB::raw('start_day')]);

        Schema::table('feeding_schedule_items', function (Blueprint $table) {
            $table->dropIndex('feeding_schedule_items_schedule_start_day_idx');
            $table->dropColumn(['start_day', 'end_day']);
            $table->integer('feeding_day')->nullable(false)->change();
        });
    }
};
