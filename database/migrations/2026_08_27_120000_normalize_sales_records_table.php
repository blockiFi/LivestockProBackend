<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_records')) {
            Schema::create('sales_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('farm_id')->constrained()->onDelete('cascade');
                $table->foreignId('flock_id')->nullable()->constrained()->onDelete('cascade');
                $table->enum('type', ['egg', 'meat', 'manure']);
                $table->decimal('quantity', 12, 2);
                $table->decimal('unit_price', 12, 2);
                $table->decimal('total_amount', 12, 2);
                $table->date('date');
                $table->foreignId('customer_id')->nullable()->constrained()->onDelete('set null');
                $table->string('customer_name')->nullable();
                $table->string('customer_phone', 50)->nullable();
                $table->string('payment_method', 50)->nullable();
                $table->enum('payment_status', ['pending', 'paid', 'partial'])->default('paid');
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
                $table->timestamps();
                $table->softDeletes();
            });

            return;
        }

        Schema::table('sales_records', function (Blueprint $table) {
            if (Schema::hasColumn('sales_records', 'price_per_unit') && ! Schema::hasColumn('sales_records', 'unit_price')) {
                $table->renameColumn('price_per_unit', 'unit_price');
            }
            if (Schema::hasColumn('sales_records', 'total_price') && ! Schema::hasColumn('sales_records', 'total_amount')) {
                $table->renameColumn('total_price', 'total_amount');
            }
        });

        if (Schema::hasColumn('sales_records', 'unit_price')) {
            Schema::table('sales_records', function (Blueprint $table) {
                $table->decimal('unit_price', 12, 2)->change();
            });
        }

        if (Schema::hasColumn('sales_records', 'total_amount')) {
            Schema::table('sales_records', function (Blueprint $table) {
                $table->decimal('total_amount', 12, 2)->change();
            });
        }

        if (Schema::hasColumn('sales_records', 'quantity')) {
            Schema::table('sales_records', function (Blueprint $table) {
                $table->decimal('quantity', 12, 2)->change();
            });
        }

        Schema::table('sales_records', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_records', 'customer_name')) {
                $table->string('customer_name')->nullable()->after('customer_id');
            }
            if (! Schema::hasColumn('sales_records', 'customer_phone')) {
                $table->string('customer_phone', 50)->nullable()->after('customer_name');
            }
            if (! Schema::hasColumn('sales_records', 'payment_method')) {
                $table->string('payment_method', 50)->nullable()->after('customer_phone');
            }
            if (! Schema::hasColumn('sales_records', 'payment_status')) {
                $table->enum('payment_status', ['pending', 'paid', 'partial'])->default('paid')->after('payment_method');
            }
            if (! Schema::hasColumn('sales_records', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('notes')->constrained('users')->onDelete('set null');
            }
        });

        if (Schema::hasColumn('sales_records', 'recorded_by') && Schema::hasColumn('sales_records', 'created_by')) {
            DB::table('sales_records')
                ->whereNull('created_by')
                ->update(['created_by' => DB::raw('recorded_by')]);
        }

        Schema::table('sales_records', function (Blueprint $table) {
            if (Schema::hasColumn('sales_records', 'sold_by')) {
                $table->dropForeign(['sold_by']);
                $table->dropColumn('sold_by');
            }
            if (Schema::hasColumn('sales_records', 'recorded_by')) {
                $table->dropForeign(['recorded_by']);
                $table->dropColumn('recorded_by');
            }
        });

        if (Schema::hasColumn('sales_records', 'customer_id')) {
            Schema::table('sales_records', function (Blueprint $table) {
                $table->foreignId('customer_id')->nullable()->change();
            });
        }

        if (Schema::hasColumn('sales_records', 'flock_id')) {
            Schema::table('sales_records', function (Blueprint $table) {
                $table->foreignId('flock_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Irreversible column normalization; no-op on rollback.
    }
};
