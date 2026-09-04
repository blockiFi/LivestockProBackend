<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flock_record_imports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('farm_id');
            $table->unsignedBigInteger('flock_id');
            $table->unsignedBigInteger('created_by');

            $table->string('source_method'); // ai|file
            $table->string('source_type'); // xlsx|csv|pdf|image
            $table->string('source_path')->nullable();
            $table->string('original_filename')->nullable();

            $table->string('status')->default('draft'); // draft|confirmed|failed

            $table->string('llm_provider')->nullable();
            $table->string('llm_model')->nullable();
            $table->longText('llm_raw_response')->nullable();

            $table->timestamps();

            $table->foreign('farm_id')->references('id')->on('farms')->onDelete('cascade');
            $table->foreign('flock_id')->references('id')->on('flocks')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flock_record_imports');
    }
};
