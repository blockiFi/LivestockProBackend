<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feeding_schedules', function (Blueprint $table) {
            $table->foreignId('poultry_type_id')->nullable()->after('type')->constrained('poultry_types');
        });
    }

    public function down(): void
    {
        Schema::table('feeding_schedules', function (Blueprint $table) {
            if (Schema::hasColumn('feeding_schedules', 'poultry_type_id')) {
                $table->dropForeign(['poultry_type_id']);
                $table->dropColumn('poultry_type_id');
            }
        });
    }
};
