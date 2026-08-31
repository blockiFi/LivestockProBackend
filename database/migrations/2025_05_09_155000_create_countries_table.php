<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('countries')) {
            return;
        }

        Schema::create('countries', function (Blueprint $table) {
            $table->bigIncrements('id');

            // ISO 3166-1 alpha-2 code, e.g. "US", "GB"
            $table->string('iso_code', 2)->unique();

            // Full English name, e.g. "United States"
            $table->string('name');

            // Currency ISO 4217 code, e.g. "USD", "GBP"
            $table->string('currency_code', 3);

            // Currency name, e.g. "United States dollar"
            $table->string('currency_name');

            // Symbol, e.g. "$", "£"
            $table->string('currency_symbol')->nullable();
            $table->enum('status' , ['active' , 'inactive'])->default('active');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
