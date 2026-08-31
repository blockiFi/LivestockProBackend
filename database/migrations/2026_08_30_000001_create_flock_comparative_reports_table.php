<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flock_comparative_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flock_id')->constrained()->cascadeOnDelete();
            $table->foreignId('poultry_type_id')->constrained()->cascadeOnDelete();
            $table->string('data_fingerprint', 64);
            $table->json('report_payload');
            $table->json('ai_insights')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['farm_id', 'flock_id']);
            $table->index(['farm_id', 'poultry_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flock_comparative_reports');
    }
};
