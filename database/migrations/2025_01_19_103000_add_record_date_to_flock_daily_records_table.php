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
            // Add the record_date field
            $table->date('record_date')->nullable()->after('flock_id');
            
            // Add other missing fields that the model expects
            $table->integer('age_days')->nullable()->after('record_date');
            $table->integer('total_birds')->nullable()->after('age_days');
            $table->integer('mortality_count')->nullable()->after('total_birds');
            $table->integer('culling_count')->nullable()->after('mortality_count');
            $table->decimal('average_weight_kg', 10, 2)->nullable()->after('culling_count');
            $table->decimal('feed_consumption_kg', 10, 2)->nullable()->after('average_weight_kg');
            $table->decimal('water_consumption_liters', 10, 2)->nullable()->after('feed_consumption_kg');
            $table->integer('egg_production_count')->nullable()->after('water_consumption_liters');
            $table->decimal('egg_weight_grams', 10, 2)->nullable()->after('egg_production_count');
            $table->decimal('temperature_celsius', 5, 2)->nullable()->after('egg_weight_grams');
            $table->decimal('humidity_percentage', 5, 2)->nullable()->after('temperature_celsius');
            $table->json('additional_data')->nullable()->after('humidity_percentage');
            $table->foreignId('created_by')->nullable()->constrained('users')->after('additional_data');
            $table->foreignId('updated_by')->nullable()->constrained('users')->after('created_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flock_daily_records', function (Blueprint $table) {
            $table->dropColumn([
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
            ]);
        });
    }
};
