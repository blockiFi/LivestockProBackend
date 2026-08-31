<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feeding_batch_schedule_items', function (Blueprint $table) {
            $table->decimal('actual_total_kg', 10, 3)->nullable()->after('actual_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('feeding_batch_schedule_items', function (Blueprint $table) {
            $table->dropColumn('actual_total_kg');
        });
    }
};
