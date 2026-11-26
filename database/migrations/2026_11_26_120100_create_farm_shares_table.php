<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schedules and feed formulations one farm has sent to another. The receiving
 * farm accepts (a copy is made then) or declines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farms', function (Blueprint $table) {
            $table->string('share_code', 16)->nullable()->unique()->after('name');
        });

        Schema::create('farm_shares', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 32); // schedule | feeding_schedule | feed_product
            $table->foreignId('source_farm_id')->constrained('farms')->cascadeOnDelete();
            $table->unsignedBigInteger('source_item_id');
            $table->string('item_name');
            $table->string('item_subtype', 32)->nullable(); // medication | vaccination for schedules
            $table->unsignedInteger('item_count')->default(0);
            $table->foreignId('target_farm_id')->constrained('farms')->cascadeOnDelete();
            $table->foreignId('shared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('message')->nullable();
            $table->string('status', 16)->default('pending'); // pending | accepted | declined | cancelled
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->unsignedBigInteger('accepted_item_id')->nullable();
            $table->timestamps();

            $table->index(['target_farm_id', 'status']);
            $table->index(['source_farm_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farm_shares');
        Schema::table('farms', function (Blueprint $table) {
            $table->dropUnique(['share_code']);
            $table->dropColumn('share_code');
        });
    }
};
