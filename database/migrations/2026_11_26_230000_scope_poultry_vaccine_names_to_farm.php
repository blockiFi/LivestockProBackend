<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vaccine names were unique across every farm, so one farm adding "Gumboro"
 * blocked all other farms from adding their own. Uniqueness is now checked
 * per farm (plus platform defaults) in VaccineController::store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('poultry_vaccines', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->index(['farm_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('poultry_vaccines', function (Blueprint $table) {
            $table->dropIndex(['farm_id', 'name']);
            $table->unique('name');
        });
    }
};
