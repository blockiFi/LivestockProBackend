<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sales order groups several product sale lines (sales_records) that were sold
 * together: one customer, one date, one payment, drawn from one or more batches.
 * Each line keeps its own flock_id, so per-flock P&L and egg stock are unchanged.
 * Existing sales_records keep sales_order_id = null and behave as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->string('type', 20);
            $table->date('date');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 50)->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->string('payment_method', 50)->nullable();
            $table->string('payment_status', 20)->default('paid');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['farm_id', 'date']);
        });

        Schema::table('sales_records', function (Blueprint $table) {
            $table->foreignId('sales_order_id')->nullable()->after('farm_id')
                ->constrained('sales_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_records', function (Blueprint $table) {
            $table->dropForeign(['sales_order_id']);
            $table->dropColumn('sales_order_id');
        });

        Schema::dropIfExists('sales_orders');
    }
};
