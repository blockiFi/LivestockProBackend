<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_products', function (Blueprint $table) {
            $table->string('pack_type', 20)->nullable();
            $table->decimal('pack_size', 12, 4)->nullable();
            $table->string('pack_size_unit', 10)->nullable();
        });

        Schema::table('poultry_medication_inventories', function (Blueprint $table) {
            $table->decimal('quantity', 14, 4)->default(0)->change();
            $table->decimal('available_quantity', 14, 4)->default(0)->change();

            $table->decimal('packs_received', 10, 2)->nullable();
            $table->decimal('pack_size_base', 12, 4)->nullable();
            $table->string('content_unit', 10)->nullable();
            $table->decimal('total_content', 14, 4)->nullable();

            $table->timestamp('depleted_at')->nullable();
            $table->foreignId('depleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('depletion_reason', 20)->nullable();
            $table->string('depletion_note', 500)->nullable();
            $table->decimal('written_off_quantity', 14, 4)->nullable();
            $table->decimal('written_off_value', 12, 2)->nullable();
        });

        Schema::table('poultry_medication_records', function (Blueprint $table) {
            $table->decimal('dosage', 12, 4)->nullable()->change();
            $table->decimal('quantity', 14, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('poultry_medication_records', function (Blueprint $table) {
            $table->integer('dosage')->nullable()->change();
            $table->decimal('quantity', 10, 2)->nullable()->change();
        });

        Schema::table('poultry_medication_inventories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('depleted_by');
            $table->dropColumn([
                'packs_received',
                'pack_size_base',
                'content_unit',
                'total_content',
                'depleted_at',
                'depletion_reason',
                'depletion_note',
                'written_off_quantity',
                'written_off_value',
            ]);
            $table->decimal('quantity', 10, 2)->default(0)->change();
            $table->decimal('available_quantity', 10, 2)->default(0)->change();
        });

        Schema::table('medication_products', function (Blueprint $table) {
            $table->dropColumn(['pack_type', 'pack_size', 'pack_size_unit']);
        });
    }
};
