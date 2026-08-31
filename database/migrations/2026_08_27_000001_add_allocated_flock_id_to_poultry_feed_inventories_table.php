<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('poultry_feed_inventories', function (Blueprint $table) {
            if (!Schema::hasColumn('poultry_feed_inventories', 'allocated_flock_id')) {
                $table->foreignId('allocated_flock_id')
                    ->nullable()
                    ->after('close_notes')
                    ->constrained('flocks')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('poultry_feed_inventories', function (Blueprint $table) {
            if (Schema::hasColumn('poultry_feed_inventories', 'allocated_flock_id')) {
                $table->dropForeign(['allocated_flock_id']);
                $table->dropColumn('allocated_flock_id');
            }
        });
    }
};
