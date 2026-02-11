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
        Schema::table('poultry_vaccines', function (Blueprint $table) {
            $table->foreignId('farm_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('poultry_vaccines', function (Blueprint $table) {
            // Delete rows with NULL farm_id before making it NOT NULL
            DB::table('poultry_vaccines')->whereNull('farm_id')->delete();
            
            $table->foreignId('farm_id')->nullable(false)->change();
        });
    }
};
