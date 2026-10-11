<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('poultry_flock_weight_reports', function (Blueprint $table) {
            $table->foreignId('house_id')->nullable()->after('flock_id')->constrained('poultry_houses')->nullOnDelete();
            $table->string('pen_label', 100)->nullable()->after('house_id');
            $table->string('sex', 10)->nullable()->after('pen_label');
            $table->string('weighing_method', 30)->nullable()->after('sex');
            $table->string('scale_reference', 100)->nullable()->after('weighing_method');
            $table->time('weighed_at_time')->nullable()->after('report_date');
            $table->unsignedInteger('age_days')->nullable()->after('weighed_at_time');

            $table->decimal('target_weight_g', 10, 1)->nullable();
            $table->decimal('average_weight_g', 10, 1)->nullable();
            $table->decimal('median_weight_g', 10, 1)->nullable();
            $table->decimal('std_dev_g', 10, 2)->nullable();
            $table->decimal('cv_percent', 6, 2)->nullable();
            $table->decimal('uniformity_percent', 6, 2)->nullable();
            $table->decimal('uniformity_band_percent', 5, 2)->default(10);
            $table->unsignedInteger('birds_within_band')->nullable();
            $table->string('uniformity_method', 12)->nullable();
            $table->decimal('deviation_from_target_g', 10, 1)->nullable();
            $table->decimal('deviation_from_target_percent', 7, 2)->nullable();
            $table->decimal('daily_gain_g', 10, 2)->nullable();

            $table->string('data_source', 20)->default('summary_only');
            $table->string('source_file_path')->nullable();
        });

        Schema::create('poultry_flock_weight_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weight_report_id')
                ->constrained('poultry_flock_weight_reports')
                ->cascadeOnDelete();
            $table->unsignedInteger('sequence')->default(0);
            $table->decimal('weight_g', 10, 1);
            $table->string('sex', 10)->nullable();
            $table->boolean('is_outlier')->default(false);
            $table->boolean('excluded')->default(false);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['weight_report_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poultry_flock_weight_samples');

        Schema::table('poultry_flock_weight_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('house_id');
            $table->dropColumn([
                'pen_label',
                'sex',
                'weighing_method',
                'scale_reference',
                'weighed_at_time',
                'age_days',
                'target_weight_g',
                'average_weight_g',
                'median_weight_g',
                'std_dev_g',
                'cv_percent',
                'uniformity_percent',
                'uniformity_band_percent',
                'birds_within_band',
                'uniformity_method',
                'deviation_from_target_g',
                'deviation_from_target_percent',
                'daily_gain_g',
                'data_source',
                'source_file_path',
            ]);
        });
    }
};
