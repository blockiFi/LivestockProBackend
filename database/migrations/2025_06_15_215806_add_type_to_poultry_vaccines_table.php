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
            $table->enum('type', ['default', 'user'])->default('default')->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('poultry_vaccines', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
