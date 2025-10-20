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
        Schema::table('poultry_feed_types', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->unique(['name', 'poultry_type_id'], 'poultry_feed_types_name_poultry_type_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('poultry_feed_types', function (Blueprint $table) {
            $table->dropUnique('poultry_feed_types_name_poultry_type_id_unique');
            $table->unique('name');
        });
    }
}; 