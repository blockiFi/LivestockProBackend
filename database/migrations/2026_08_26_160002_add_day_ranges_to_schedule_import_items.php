<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_import_items', function (Blueprint $table) {
            $table->unsignedInteger('start_day')->nullable()->after('feeding_day');
            $table->unsignedInteger('end_day')->nullable()->after('start_day');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_import_items', function (Blueprint $table) {
            $table->dropColumn(['start_day', 'end_day']);
        });
    }
};
