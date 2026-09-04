<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('poultry_flock_egg_reports', function (Blueprint $table) {
            $table->unsignedInteger('eggs_broken')->default(0)->after('eggs_collected');
        });
    }

    public function down(): void
    {
        Schema::table('poultry_flock_egg_reports', function (Blueprint $table) {
            $table->dropColumn('eggs_broken');
        });
    }
};
