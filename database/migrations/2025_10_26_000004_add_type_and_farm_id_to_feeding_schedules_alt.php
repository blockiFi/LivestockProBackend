<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feeding_schedules', function (Blueprint $table) {
            if (!Schema::hasColumn('feeding_schedules', 'type')) {
                $table->enum('type', ['default', 'user'])->default('default')->after('end_date');
            }
            if (!Schema::hasColumn('feeding_schedules', 'farm_id')) {
                $table->foreignId('farm_id')->nullable()->after('type')->constrained()->onDelete('cascade');
            }
        });
    }

    public function down(): void
    {
        Schema::table('feeding_schedules', function (Blueprint $table) {
            if (Schema::hasColumn('feeding_schedules', 'farm_id')) {
                $table->dropForeign(['farm_id']);
                $table->dropColumn('farm_id');
            }
            if (Schema::hasColumn('feeding_schedules', 'type')) {
                $table->dropColumn('type');
            }
        });
    }
};
