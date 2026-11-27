<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            // Paystack plan per longer cycle: {"biannual": {"code": "PLN_x", "amount": 2850000}, ...}.
            // The monthly code stays in paystack_plan_code.
            $table->json('paystack_cycle_plans')->nullable()->after('paystack_plan_code');
        });

        Schema::table('farm_subscriptions', function (Blueprint $table) {
            $table->string('billing_cycle', 20)->default('monthly')->after('subscription_plan_id');
        });

        Schema::table('subscription_transactions', function (Blueprint $table) {
            $table->string('billing_cycle', 20)->nullable()->after('subscription_plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_transactions', function (Blueprint $table) {
            $table->dropColumn('billing_cycle');
        });

        Schema::table('farm_subscriptions', function (Blueprint $table) {
            $table->dropColumn('billing_cycle');
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('paystack_cycle_plans');
        });
    }
};
