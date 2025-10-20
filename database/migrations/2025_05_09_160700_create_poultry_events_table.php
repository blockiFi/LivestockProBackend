<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('poultry_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->foreignId('flock_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('event_type')->nullable();
            $table->string('table_name')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->date('event_date')->nullable();
            $table->text('event');
            $table->foreignId('performed_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('poultry_events');
    }
};
