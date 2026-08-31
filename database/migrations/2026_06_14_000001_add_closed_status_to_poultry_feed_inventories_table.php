<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('poultry_feed_inventories', 'status')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            "ALTER TABLE poultry_feed_inventories MODIFY COLUMN status ENUM('available', 'in_use', 'depleted', 'closed') NOT NULL DEFAULT 'available'"
        );
    }

    public function down(): void
    {
        if (!Schema::hasColumn('poultry_feed_inventories', 'status')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            "ALTER TABLE poultry_feed_inventories MODIFY COLUMN status ENUM('available', 'in_use', 'depleted') NOT NULL DEFAULT 'available'"
        );
    }
};
