<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_records') && ! Schema::hasColumn('sales_records', 'amount_paid')) {
            Schema::table('sales_records', function (Blueprint $table) {
                $table->decimal('amount_paid', 12, 2)->default(0)->after('total_amount');
            });

            DB::table('sales_records')
                ->where('payment_status', 'paid')
                ->update(['amount_paid' => DB::raw('total_amount')]);
        }

        if (Schema::hasTable('invoices') && ! Schema::hasColumn('invoices', 'amount_paid')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->decimal('amount_paid', 12, 2)->default(0)->after('total');
            });

            DB::table('invoices')
                ->where('status', 'paid')
                ->update(['amount_paid' => DB::raw('total')]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_records', 'amount_paid')) {
            Schema::table('sales_records', function (Blueprint $table) {
                $table->dropColumn('amount_paid');
            });
        }

        if (Schema::hasColumn('invoices', 'amount_paid')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn('amount_paid');
            });
        }
    }
};
