<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farm_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->unique()->constrained()->onDelete('cascade');
            $table->string('currency_code', 3)->default('NGN');
            $table->string('currency_symbol', 8)->default('N');
            $table->string('timezone')->default('UTC');
            $table->string('date_format', 20)->default('Y-m-d');
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1);
            $table->string('invoice_prefix', 20)->default('INV');
            $table->unsignedInteger('invoice_next_number')->default(1);
            $table->boolean('invoice_tax_enabled')->default(true);
            $table->decimal('invoice_tax_rate', 5, 2)->default(10.00);
            $table->text('invoice_payment_instructions')->nullable();
            $table->text('invoice_footer_note')->nullable();
            $table->unsignedSmallInteger('schedule_reminder_days')->default(7);
            $table->boolean('low_stock_alerts_enabled')->default(true);
            $table->decimal('mortality_alert_percent', 5, 2)->default(2.00);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farm_settings');
    }
};
