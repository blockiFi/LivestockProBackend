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
        Schema::table('poultry_feed_inventories', function (Blueprint $table) {
            if (!Schema::hasColumn('poultry_feed_inventories', 'available_quantity')) {
                $table->decimal('available_quantity', 10, 2)->default(0)->after('quantity');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('poultry_feed_inventories', function (Blueprint $table) {
            if (Schema::hasColumn('poultry_feed_inventories', 'available_quantity')) {
                $table->dropColumn('available_quantity');
            }
        });
    }
};
