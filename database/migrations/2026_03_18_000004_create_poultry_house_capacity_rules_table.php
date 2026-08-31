<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // If this table already exists (e.g. created by a previous failed migration attempt),
        // do NOT try to create it again. Instead, ensure the intended indexes exist.
        if (Schema::hasTable('poultry_house_capacity_rules')) {
            // Ensure unique index exists with a short name (MySQL identifier length limit).
            try {
                $exists = DB::table('information_schema.statistics')
                    ->where('table_schema', DB::raw('DATABASE()'))
                    ->where('table_name', 'poultry_house_capacity_rules')
                    ->where('index_name', 'phcr_house_age_unique')
                    ->exists();
            } catch (\Throwable $e) {
                $exists = false;
            }

            if (!$exists) {
                Schema::table('poultry_house_capacity_rules', function (Blueprint $table) {
                    // If a long auto-generated unique index exists, adding this may fail; ignore safely.
                    try {
                        $table->unique(['house_id', 'min_age_days', 'max_age_days'], 'phcr_house_age_unique');
                    } catch (\Throwable $e) {
                        // no-op
                    }
                });
            }

            return;
        }

        Schema::create('poultry_house_capacity_rules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->foreignId('house_id')->constrained('poultry_houses')->onDelete('cascade');
            $table->unsignedInteger('min_age_days');
            $table->unsignedInteger('max_age_days');
            $table->unsignedInteger('capacity');
            $table->timestamps();

            // MySQL has a limit on identifier length; provide a shorter explicit index name.
            $table->unique(['house_id', 'min_age_days', 'max_age_days'], 'phcr_house_age_unique');
            $table->index(['farm_id', 'house_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poultry_house_capacity_rules');
    }
};

