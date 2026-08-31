<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batch_schedule_items', function (Blueprint $table) {
            $table->foreignId('poultry_medication_id')->nullable()->change();
            $table->foreignId('administration_method_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('batch_schedule_items', function (Blueprint $table) {
            $table->foreignId('poultry_medication_id')->nullable(false)->change();
            $table->foreignId('administration_method_id')->nullable(false)->change();
        });
    }
};
