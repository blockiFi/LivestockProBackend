<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('poultry_feed_inventories', function (Blueprint $table) {
            if (!Schema::hasColumn('poultry_feed_inventories', 'damaged_quantity')) {
                $table->decimal('damaged_quantity', 10, 2)->default(0)->after('quantity');
            }
            if (!Schema::hasColumn('poultry_feed_inventories', 'closed_at')) {
                $table->timestamp('closed_at')->nullable()->after('damaged_quantity');
            }
            if (!Schema::hasColumn('poultry_feed_inventories', 'closed_by')) {
                $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('poultry_feed_inventories', 'close_notes')) {
                $table->text('close_notes')->nullable()->after('closed_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('poultry_feed_inventories', function (Blueprint $table) {
            if (Schema::hasColumn('poultry_feed_inventories', 'close_notes')) {
                $table->dropColumn('close_notes');
            }
            if (Schema::hasColumn('poultry_feed_inventories', 'closed_by')) {
                $table->dropConstrainedForeignId('closed_by');
            }
            if (Schema::hasColumn('poultry_feed_inventories', 'closed_at')) {
                $table->dropColumn('closed_at');
            }
            if (Schema::hasColumn('poultry_feed_inventories', 'damaged_quantity')) {
                $table->dropColumn('damaged_quantity');
            }
        });
    }
};
