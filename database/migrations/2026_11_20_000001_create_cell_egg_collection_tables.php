<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional cell-based egg collection.
 *
 * Layout: a house has lettered rows (A, B, C...), each row has numbered cells (A1, A2...),
 * each cell holds a number of birds and may be assigned to a flock.
 *
 * Collection: a daily egg report (one per flock per day) can hold several collection
 * rounds; each round is either a plain total or a set of per-cell counts. When a report
 * has rounds, its totals are the sum of its rounds. Reports without rounds keep working
 * exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('poultry_houses', function (Blueprint $table) {
            $table->string('egg_collection_mode', 10)->default('total')->after('status');
            $table->unsignedSmallInteger('birds_per_cell')->nullable()->after('egg_collection_mode');
        });

        Schema::create('poultry_house_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('house_id')->constrained('poultry_houses')->cascadeOnDelete();
            $table->string('code', 10);
            $table->unsignedSmallInteger('cell_count');
            $table->unsignedSmallInteger('birds_per_cell')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['house_id', 'code']);
        });

        Schema::create('poultry_house_cells', function (Blueprint $table) {
            $table->id();
            $table->foreignId('row_id')->constrained('poultry_house_rows')->cascadeOnDelete();
            $table->unsignedSmallInteger('cell_number');
            $table->unsignedSmallInteger('birds')->default(0);
            $table->foreignId('flock_id')->nullable()->constrained('flocks')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['row_id', 'cell_number']);
            $table->index('flock_id');
        });

        Schema::table('poultry_flock_egg_reports', function (Blueprint $table) {
            $table->string('entry_mode', 10)->default('total')->after('eggs_broken');
        });

        Schema::create('poultry_flock_egg_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('egg_report_id')->constrained('poultry_flock_egg_reports')->cascadeOnDelete();
            $table->unsignedSmallInteger('round_no');
            $table->time('collected_at')->nullable();
            $table->string('entry_mode', 10)->default('total');
            $table->string('source', 20)->default('manual');
            $table->unsignedInteger('eggs_collected')->default(0);
            $table->unsignedInteger('eggs_broken_cells')->default(0);
            $table->unsignedInteger('eggs_broken_extra')->default(0);
            $table->decimal('average_egg_weight', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['egg_report_id', 'round_no']);
        });

        Schema::create('poultry_flock_egg_collection_cells', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained('poultry_flock_egg_collections')->cascadeOnDelete();
            $table->foreignId('cell_id')->constrained('poultry_house_cells')->cascadeOnDelete();
            $table->unsignedSmallInteger('eggs')->default(0);
            $table->unsignedSmallInteger('broken')->default(0);
            $table->unsignedSmallInteger('birds')->default(0);
            $table->timestamps();

            $table->unique(['collection_id', 'cell_id']);
            $table->index('cell_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poultry_flock_egg_collection_cells');
        Schema::dropIfExists('poultry_flock_egg_collections');

        Schema::table('poultry_flock_egg_reports', function (Blueprint $table) {
            $table->dropColumn('entry_mode');
        });

        Schema::dropIfExists('poultry_house_cells');
        Schema::dropIfExists('poultry_house_rows');

        Schema::table('poultry_houses', function (Blueprint $table) {
            $table->dropColumn(['egg_collection_mode', 'birds_per_cell']);
        });
    }
};
