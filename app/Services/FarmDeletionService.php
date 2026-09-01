<?php

namespace App\Services;

use App\Models\EquipmentDocument;
use App\Models\Farm;
use App\Models\ScheduleImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FarmDeletionService
{
    /**
     * Permanently remove a farm and all related database rows/files.
     */
    public function purge(Farm $farm): void
    {
        DB::transaction(function () use ($farm) {
            $this->deleteStoredAssets($farm);
            $this->cleanupSpatieTeamData($farm->id);
            $farm->users()->detach();
            $farm->forceDelete();
        });
    }

    /**
     * Remove farm-scoped Spatie roles and pivot rows (no FK to farms table).
     */
    public function cleanupSpatieTeamData(int $farmId): void
    {
        $teamKey = config('permission.column_names.team_foreign_key', 'farm_id');
        $rolesTable = config('permission.table_names.roles');
        $modelHasRolesTable = config('permission.table_names.model_has_roles');
        $modelHasPermissionsTable = config('permission.table_names.model_has_permissions');
        $roleHasPermissionsTable = config('permission.table_names.role_has_permissions');

        $roleIds = DB::table($rolesTable)->where($teamKey, $farmId)->pluck('id');

        if ($roleIds->isNotEmpty()) {
            DB::table($roleHasPermissionsTable)->whereIn('role_id', $roleIds)->delete();
        }

        DB::table($modelHasRolesTable)->where($teamKey, $farmId)->delete();
        DB::table($modelHasPermissionsTable)->where($teamKey, $farmId)->delete();
        DB::table($rolesTable)->where($teamKey, $farmId)->delete();
    }

    private function deleteStoredAssets(Farm $farm): void
    {
        if ($farm->logo) {
            Storage::disk('public')->delete($farm->logo);
        }

        EquipmentDocument::query()
            ->where('farm_id', $farm->id)
            ->pluck('storage_path')
            ->filter()
            ->each(fn (string $path) => Storage::disk('public')->delete($path));

        ScheduleImport::query()
            ->where('farm_id', $farm->id)
            ->pluck('source_path')
            ->filter()
            ->each(fn (string $path) => Storage::disk('public')->delete($path));
    }
}
