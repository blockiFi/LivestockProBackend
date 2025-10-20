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
        Schema::table('poultry_medication_inventories', function (Blueprint $table) {
            $table->dropForeign(['poultry_medication_id']);
            $table->dropColumn('poultry_medication_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('poultry_medication_inventories', function (Blueprint $table) {
            $table->foreignId('poultry_medication_id')->constrained()->onDelete('cascade');
        });
    }
}; 