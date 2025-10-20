<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('flock_daily_records', function (Blueprint $table) {
            // Add record_date field if it doesn't exist
            if (!Schema::hasColumn('flock_daily_records', 'record_date')) {
                $table->date('record_date')->nullable()->after('flock_id');
            }
            
            // Add other missing fields that the model expects
            if (!Schema::hasColumn('flock_daily_records', 'age_days')) {
                $table->integer('age_days')->nullable()->after('record_date');
            }
            
            if (!Schema::hasColumn('flock_daily_records', 'total_birds')) {
                $table->integer('total_birds')->nullable()->after('age_days');
            }
            
            if (!Schema::hasColumn('flock_daily_records', 'mortality_count')) {
                $table->integer('mortality_count')->nullable()->after('total_birds');
            }
            
            if (!Schema::hasColumn('flock_daily_records', 'culling_count')) {
                $table->integer('culling_count')->nullable()->after('mortality_count');
            }
            
            if (!Schema::hasColumn('flock_daily_records', 'average_weight_kg')) {
                $table->decimal('average_weight_kg', 10, 2)->nullable()->after('culling_count');
            }
            
            if (!Schema::hasColumn('flock_daily_records', 'feed_consumption_kg')) {
                $table->decimal('feed_consumption_kg', 10, 2)->nullable()->after('average_weight_kg');
            }
            
            if (!Schema::hasColumn('flock_daily_records', 'water_consumption_liters')) {
                $table->decimal('water_consumption_liters', 10, 2)->nullable()->after('feed_consumption_kg');
            }
            
            if (!Schema::hasColumn('flock_daily_records', 'egg_production_count')) {
                $table->integer('egg_production_count')->nullable()->after('water_consumption_liters');
            }
            
            if (!Schema::hasColumn('flock_daily_records', 'egg_weight_grams')) {
                $table->decimal('egg_weight_grams', 10, 2)->nullable()->after('egg_production_count');
            }
            
            if (!Schema::hasColumn('flock_daily_records', 'temperature_celsius')) {
                $table->decimal('temperature_celsius', 5, 2)->nullable()->after('egg_weight_grams');
            }
            
            if (!Schema::hasColumn('flock_daily_records', 'humidity_percentage')) {
                $table->decimal('humidity_percentage', 5, 2)->nullable()->after('temperature_celsius');
            }
            
            if (!Schema::hasColumn('flock_daily_records', 'additional_data')) {
                $table->json('additional_data')->nullable()->after('humidity_percentage');
            }
            
            if (!Schema::hasColumn('flock_daily_records', 'created_by')) {
                $table->foreignId('created_by')->nullable()->constrained('users')->after('additional_data');
            }
            
            if (!Schema::hasColumn('flock_daily_records', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->constrained('users')->after('created_by');
            }
            
            // Make the existing date field nullable to avoid conflicts
            $table->date('date')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flock_daily_records', function (Blueprint $table) {
            // Remove the added columns
            $columns = [
                'record_date',
                'age_days', 
                'total_birds',
                'mortality_count',
                'culling_count',
                'average_weight_kg',
                'feed_consumption_kg',
                'water_consumption_liters',
                'egg_production_count',
                'egg_weight_grams',
                'temperature_celsius',
                'humidity_percentage',
                'additional_data',
                'created_by',
                'updated_by'
            ];
            
            foreach ($columns as $column) {
                if (Schema::hasColumn('flock_daily_records', $column)) {
                    $table->dropColumn($column);
                }
            }
            
            // Revert the date field to not nullable
            $table->date('date')->nullable(false)->change();
        });
    }
};
