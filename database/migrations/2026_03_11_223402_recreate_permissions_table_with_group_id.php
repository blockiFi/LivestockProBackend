<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tableNames = config('permission.table_names');
        $permissionsTable = $tableNames['permissions'] ?? 'permissions';

        // If permissions table doesn't exist, just create it with group_id.
        if (!Schema::hasTable($permissionsTable)) {
            Schema::create($permissionsTable, function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('guard_name');
                $table->foreignId('group_id')->nullable()->constrained('groups')->nullOnDelete();
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
        } else {
            // If table exists but group_id column is missing, add it.
            if (!Schema::hasColumn($permissionsTable, 'group_id')) {
                Schema::table($permissionsTable, function (Blueprint $table) {
                    $table->foreignId('group_id')->nullable()->constrained('groups')->nullOnDelete();
                });
            }
        }

        // Clear permission cache
        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableNames = config('permission.table_names');
        
        // Drop foreign keys
        Schema::table($tableNames['role_has_permissions'], function (Blueprint $table) use ($tableNames) {
            $table->dropForeign(['permission_id']);
        });
        
        Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) use ($tableNames) {
            $table->dropForeign(['permission_id']);
        });

        // Drop permissions table
        Schema::dropIfExists($tableNames['permissions']);

        // Recreate without group_id (original structure)
        Schema::create($tableNames['permissions'], function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();

            $table->unique(['name', 'guard_name']);
        });

        // Re-add foreign keys
        Schema::table($tableNames['role_has_permissions'], function (Blueprint $table) use ($tableNames) {
            $table->foreign('permission_id')
                ->references('id')
                ->on($tableNames['permissions'])
                ->onDelete('cascade');
        });
        
        Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) use ($tableNames) {
            $table->foreign('permission_id')
                ->references('id')
                ->on($tableNames['permissions'])
                ->onDelete('cascade');
        });
    }
};
