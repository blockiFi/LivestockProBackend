<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->nullable()->constrained()->nullOnDelete();
            // paystack_webhook | admin_waiver | admin_override | checkout
            $table->string('source');
            $table->string('event')->nullable();
            $table->unsignedBigInteger('amount_kobo')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('status')->nullable();
            $table->string('reference')->nullable();
            $table->json('payload')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['farm_id', 'created_at']);
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_transactions');
    }
};
