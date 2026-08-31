<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['farm_id', 'slug']);
        });

        Schema::create('equipment_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('asset_id_prefix', 16)->default('EQP');
            $table->string('asset_id_format', 64)->default('{prefix}-{year}-{seq}');
            $table->json('warranty_reminder_days')->nullable(); // e.g. [30,14,7,0]
            $table->json('maintenance_reminder_days')->nullable(); // e.g. [7,3,1]
            $table->timestamps();
        });

        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('equipment_categories')->nullOnDelete();

            $table->string('asset_id', 32);
            $table->string('name');
            $table->string('equipment_type')->nullable();
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('unit', 32)->nullable();

            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_price', 15, 2)->nullable();
            $table->string('supplier')->nullable();
            $table->string('invoice_reference')->nullable();
            $table->string('purchase_order_number')->nullable();
            $table->string('payment_status', 32)->nullable();
            $table->unsignedSmallInteger('warranty_period_months')->nullable();
            $table->date('warranty_expires_at')->nullable();

            $table->string('farm_section', 64)->nullable();
            $table->string('location')->nullable();
            $table->string('department')->nullable();
            $table->foreignId('poultry_house_id')->nullable()->constrained('poultry_houses')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            $table->string('status', 32)->default('available');
            $table->string('condition', 32)->default('good');
            $table->date('placed_in_service_date')->nullable();
            $table->unsignedSmallInteger('expected_useful_life_months')->nullable();
            $table->decimal('current_usage_value', 15, 2)->nullable();
            $table->string('usage_metric', 32)->nullable(); // hours|km|cycles|count
            $table->date('last_inspection_date')->nullable();
            $table->date('next_inspection_date')->nullable();
            $table->unsignedSmallInteger('maintenance_interval_days')->nullable();
            $table->date('next_maintenance_date')->nullable();
            $table->date('last_maintenance_date')->nullable();

            $table->string('qr_code_path')->nullable();
            $table->decimal('total_maintenance_cost', 15, 2)->default(0);
            $table->decimal('total_repair_cost', 15, 2)->default(0);
            $table->decimal('total_other_cost', 15, 2)->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['farm_id', 'asset_id']);
            $table->index(['farm_id', 'status']);
            $table->index(['farm_id', 'category_id']);
            $table->index(['farm_id', 'next_maintenance_date']);
            $table->index(['farm_id', 'warranty_expires_at']);
        });

        Schema::create('equipment_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('farm_section', 64)->nullable();
            $table->string('location')->nullable();
            $table->string('department')->nullable();
            $table->foreignId('poultry_house_id')->nullable()->constrained('poultry_houses')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('released_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamps();

            $table->index(['equipment_id', 'is_current']);
        });

        Schema::create('equipment_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->string('previous_location')->nullable();
            $table->string('new_location')->nullable();
            $table->string('previous_section', 64)->nullable();
            $table->string('new_section', 64)->nullable();
            $table->string('previous_department')->nullable();
            $table->string('new_department')->nullable();
            $table->foreignId('previous_assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('new_assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('previous_house_id')->nullable()->constrained('poultry_houses')->nullOnDelete();
            $table->foreignId('new_house_id')->nullable()->constrained('poultry_houses')->nullOnDelete();
            $table->timestamp('transferred_at');
            $table->foreignId('transferred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('equipment_maintenance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->string('maintenance_type', 32)->default('scheduled');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->date('performed_at');
            $table->date('next_due_at')->nullable();
            $table->string('service_provider')->nullable();
            $table->string('technician')->nullable();
            $table->text('parts_replaced')->nullable();
            $table->decimal('labour_cost', 15, 2)->default(0);
            $table->decimal('parts_cost', 15, 2)->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['equipment_id', 'performed_at']);
            $table->index(['farm_id', 'next_due_at']);
        });

        Schema::create('equipment_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->date('inspection_date');
            $table->foreignId('inspector_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('condition', 32)->nullable();
            $table->text('findings')->nullable();
            $table->text('problems_identified')->nullable();
            $table->text('recommended_action')->nullable();
            $table->text('notes')->nullable();
            $table->date('next_inspection_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('equipment_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->string('document_type', 32)->default('other');
            $table->string('name');
            $table->string('storage_path');
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->date('expires_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('equipment_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->string('metric', 32);
            $table->decimal('value', 15, 2);
            $table->decimal('delta', 15, 2)->nullable();
            $table->date('recorded_at');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('equipment_retirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->unique()->constrained('equipment')->cascadeOnDelete();
            $table->string('disposal_method', 32); // retired|sold|scrapped|donated|disposed|lost
            $table->date('disposal_date');
            $table->text('reason')->nullable();
            $table->string('final_condition', 32)->nullable();
            $table->decimal('sale_price', 15, 2)->nullable();
            $table->string('buyer_recipient')->nullable();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('equipment_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->string('action', 64);
            $table->string('summary');
            $table->json('meta')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['equipment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_activity_logs');
        Schema::dropIfExists('equipment_retirements');
        Schema::dropIfExists('equipment_usage_logs');
        Schema::dropIfExists('equipment_documents');
        Schema::dropIfExists('equipment_inspections');
        Schema::dropIfExists('equipment_maintenance_logs');
        Schema::dropIfExists('equipment_transfers');
        Schema::dropIfExists('equipment_assignments');
        Schema::dropIfExists('equipment');
        Schema::dropIfExists('equipment_settings');
        Schema::dropIfExists('equipment_categories');
    }
};
