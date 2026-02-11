<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

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
            // Check if the index exists before trying to drop it
            $indexes = DB::select("SHOW INDEX FROM poultry_feed_types WHERE Key_name = 'poultry_feed_types_name_poultry_type_id_unique'");
            
            if (!empty($indexes)) {
                $table->dropUnique('poultry_feed_types_name_poultry_type_id_unique');
            }
            // Don't add back the name-only unique constraint as it would fail
            // if there are feed types with same name for different poultry types
        });
    }
}; 