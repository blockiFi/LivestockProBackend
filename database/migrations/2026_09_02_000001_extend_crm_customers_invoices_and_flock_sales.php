<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('company_name')->nullable()->after('name');
            $table->text('notes')->nullable()->after('state');
            $table->boolean('is_active')->default(true)->after('notes');
        });

        // Drop global unique on name and enforce per-farm uniqueness.
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['name']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->unique(['farm_id', 'name']);
        });

        Schema::table('flock_sales', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('farm_id')->constrained()->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('subtotal', 12, 2)->default(0)->after('due_date');
            $table->decimal('tax_amount', 12, 2)->default(0)->after('subtotal');
            $table->foreignId('created_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
        });

        $this->backfillFlockSaleCustomers();
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['subtotal', 'tax_amount']);
        });

        Schema::table('flock_sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['farm_id', 'name']);
            $table->dropColumn(['company_name', 'notes', 'is_active']);
            $table->unique('name');
        });
    }

    private function backfillFlockSaleCustomers(): void
    {
        if (! Schema::hasTable('flock_sales') || ! Schema::hasTable('customers')) {
            return;
        }

        DB::table('flock_sales')
            ->whereNull('customer_id')
            ->whereNotNull('customer_name')
            ->orderBy('id')
            ->chunkById(200, function ($sales) {
                foreach ($sales as $sale) {
                    $customerId = DB::table('customers')
                        ->where('farm_id', $sale->farm_id)
                        ->where('name', $sale->customer_name)
                        ->value('id');

                    if ($customerId) {
                        DB::table('flock_sales')
                            ->where('id', $sale->id)
                            ->update(['customer_id' => $customerId]);
                    }
                }
            });
    }
};
