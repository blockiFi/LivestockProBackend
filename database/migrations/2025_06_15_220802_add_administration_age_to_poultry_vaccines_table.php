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
        Schema::table('poultry_vaccines', function (Blueprint $table) {
            $table->integer('administration_age')->nullable()->after('description')
                ->comment('Recommended age in days for vaccine administration');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('poultry_vaccines', function (Blueprint $table) {
            $table->dropColumn('administration_age');
        });
    }
};
