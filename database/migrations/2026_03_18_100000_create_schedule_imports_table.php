<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_imports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('farm_id');
            $table->unsignedBigInteger('created_by');

            $table->string('source_type'); // pdf|image
            $table->string('source_path'); // stored file path

            $table->string('status')->default('draft'); // draft|confirmed|failed

            $table->string('llm_provider')->nullable();
            $table->string('llm_model')->nullable();
            $table->longText('llm_raw_response')->nullable();

            $table->timestamps();

            $table->foreign('farm_id')->references('id')->on('farms')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_imports');
    }
};

