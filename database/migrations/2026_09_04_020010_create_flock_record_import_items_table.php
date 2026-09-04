<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flock_record_import_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('flock_record_import_id');

            $table->string('record_type'); // daily|mortality|eggs|feed_usage|expenditure|flock_sale|product_sale
            $table->unsignedInteger('row_index')->default(0);
            $table->json('payload');
            $table->decimal('confidence', 5, 2)->nullable();
            $table->json('validation_errors')->nullable();
            $table->string('status')->default('pending'); // pending|valid|invalid|committed|skipped

            $table->string('created_resource_type')->nullable();
            $table->unsignedBigInteger('created_resource_id')->nullable();

            $table->timestamps();

            $table->foreign('flock_record_import_id', 'fri_items_import_fk')
                ->references('id')
                ->on('flock_record_imports')
                ->onDelete('cascade');

            $table->index(['flock_record_import_id', 'record_type'], 'fri_items_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flock_record_import_items');
    }
};
