<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->decimal('balance', 15, 2)->default(0);
            $table->string('currency', 10)->nullable();
            $table->string('status', 20)->default('active');
            $table->decimal('low_balance_threshold', 15, 2)->nullable();
            $table->decimal('total_credited', 15, 2)->default(0);
            $table->decimal('total_debited', 15, 2)->default(0);
            $table->timestamp('last_top_up_at')->nullable();
            $table->timestamp('last_payment_at')->nullable();
            $table->timestamps();

            $table->unique('customer_id', 'cust_accounts_customer_uidx');
            $table->index(['farm_id', 'status'], 'cust_accounts_farm_status_idx');
            $table->index(['farm_id', 'balance'], 'cust_accounts_farm_balance_idx');
        });

        Schema::create('customer_account_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique('cust_acct_txn_uuid_uidx');
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_account_id')->constrained('customer_accounts')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('direction', 10);
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_before', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->string('payment_method', 50)->nullable();
            $table->string('reference', 191)->nullable();
            $table->string('description', 500)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('sales_record_id')->nullable()->constrained('sales_records')->nullOnDelete();
            $table->unsignedBigInteger('reverses_transaction_id')->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->foreign('reverses_transaction_id', 'cust_acct_txn_reverses_fk')
                ->references('id')
                ->on('customer_account_transactions')
                ->nullOnDelete();

            $table->unique(['farm_id', 'idempotency_key'], 'cust_acct_txn_farm_idem_uidx');
            $table->index(['customer_id', 'created_at'], 'cust_acct_txn_customer_created_idx');
            $table->index(['customer_account_id', 'created_at'], 'cust_acct_txn_account_created_idx');
            $table->index(['farm_id', 'type'], 'cust_acct_txn_farm_type_idx');
            $table->index('sales_record_id', 'cust_acct_txn_sale_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_account_transactions');
        Schema::dropIfExists('customer_accounts');
    }
};
