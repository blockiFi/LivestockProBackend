<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->unsignedBigInteger('price_kobo');
            $table->string('currency', 3)->default('NGN');
            // Null limits mean unlimited.
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_active_flocks')->nullable();
            $table->boolean('ai_enabled')->default(false);
            $table->string('paystack_plan_code')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
