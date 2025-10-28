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
        Schema::table('poultry_feed_types', function (Blueprint $table) {
            if (!Schema::hasColumn('poultry_feed_types', 'min_stock_level')) {
                $table->decimal('min_stock_level', 10, 2)->default(0)->after('end_age');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('poultry_feed_types', function (Blueprint $table) {
            if (Schema::hasColumn('poultry_feed_types', 'min_stock_level')) {
                $table->dropColumn('min_stock_level');
            }
        });
    }
};
