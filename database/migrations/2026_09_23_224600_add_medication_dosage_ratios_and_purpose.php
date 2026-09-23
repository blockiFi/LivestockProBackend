<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Product label mix ratios (preventive + treatment) and usage purpose on records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_products', function (Blueprint $table) {
            $table->decimal('preventive_medicine_amount', 12, 4)->nullable()->after('dosage_unit');
            $table->string('preventive_medicine_unit', 50)->nullable()->after('preventive_medicine_amount');
            $table->decimal('preventive_diluent_amount', 12, 4)->nullable()->after('preventive_medicine_unit');
            $table->string('preventive_diluent_unit', 50)->nullable()->after('preventive_diluent_amount');
            $table->string('preventive_diluent_type', 20)->nullable()->after('preventive_diluent_unit');
            $table->string('preventive_diluent_label', 100)->nullable()->after('preventive_diluent_type');

            $table->decimal('treatment_medicine_amount', 12, 4)->nullable()->after('preventive_diluent_label');
            $table->string('treatment_medicine_unit', 50)->nullable()->after('treatment_medicine_amount');
            $table->decimal('treatment_diluent_amount', 12, 4)->nullable()->after('treatment_medicine_unit');
            $table->string('treatment_diluent_unit', 50)->nullable()->after('treatment_diluent_amount');
            $table->string('treatment_diluent_type', 20)->nullable()->after('treatment_diluent_unit');
            $table->string('treatment_diluent_label', 100)->nullable()->after('treatment_diluent_type');
        });

        // Backfill treatment medicine from legacy dosage fields.
        DB::table('medication_products')
            ->whereNotNull('dosage')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('medication_products')
                        ->where('id', $row->id)
                        ->update([
                            'treatment_medicine_amount' => $row->dosage,
                            'treatment_medicine_unit' => $row->dosage_unit ?: 'ml',
                        ]);
                }
            });

        if (Schema::hasTable('poultry_medication_records')
            && ! Schema::hasColumn('poultry_medication_records', 'purpose')) {
            Schema::table('poultry_medication_records', function (Blueprint $table) {
                $table->string('purpose', 20)->nullable()->after('dosage_unit');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('poultry_medication_records', 'purpose')) {
            Schema::table('poultry_medication_records', function (Blueprint $table) {
                $table->dropColumn('purpose');
            });
        }

        Schema::table('medication_products', function (Blueprint $table) {
            $table->dropColumn([
                'preventive_medicine_amount',
                'preventive_medicine_unit',
                'preventive_diluent_amount',
                'preventive_diluent_unit',
                'preventive_diluent_type',
                'preventive_diluent_label',
                'treatment_medicine_amount',
                'treatment_medicine_unit',
                'treatment_diluent_amount',
                'treatment_diluent_unit',
                'treatment_diluent_type',
                'treatment_diluent_label',
            ]);
        });
    }
};
