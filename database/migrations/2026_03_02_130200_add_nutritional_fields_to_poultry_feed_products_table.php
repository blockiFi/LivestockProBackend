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
        Schema::table('poultry_feed_products', function (Blueprint $table) {
            $table->decimal('crude_protein', 5, 2)->nullable()->after('description');
            $table->decimal('crude_fat', 5, 2)->nullable()->after('crude_protein');
            $table->decimal('crude_fiber', 5, 2)->nullable()->after('crude_fat');
            $table->decimal('calcium', 5, 2)->nullable()->after('crude_fiber');
            $table->decimal('phosphorus', 5, 2)->nullable()->after('calcium');
            $table->decimal('metabolizable_energy', 8, 2)->nullable()->after('phosphorus');
            $table->decimal('moisture', 5, 2)->nullable()->after('metabolizable_energy');
            $table->decimal('ash', 5, 2)->nullable()->after('moisture');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('poultry_feed_products', function (Blueprint $table) {
            $table->dropColumn([
                'crude_protein',
                'crude_fat',
                'crude_fiber',
                'calcium',
                'phosphorus',
                'metabolizable_energy',
                'moisture',
                'ash',
            ]);
        });
    }
};

