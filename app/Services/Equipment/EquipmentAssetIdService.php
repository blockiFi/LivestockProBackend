<?php

namespace App\Services\Equipment;

use App\Models\Equipment;
use App\Models\EquipmentSetting;
use App\Models\Farm;

class EquipmentAssetIdService
{
    public function settingsFor(Farm $farm): EquipmentSetting
    {
        return EquipmentSetting::firstOrCreate(
            ['farm_id' => $farm->id],
            [
                'asset_id_prefix' => 'EQP',
                'asset_id_format' => '{prefix}-{year}-{seq}',
                'warranty_reminder_days' => [30, 14, 7, 0],
                'maintenance_reminder_days' => [7, 3, 1],
            ]
        );
    }

    public function nextAssetId(Farm $farm): string
    {
        $settings = $this->settingsFor($farm);
        $year = now()->year;
        $prefix = $settings->asset_id_prefix ?: 'EQP';

        $latest = Equipment::withTrashed()
            ->where('farm_id', $farm->id)
            ->where('asset_id', 'like', "{$prefix}-{$year}-%")
            ->orderByDesc('id')
            ->value('asset_id');

        $seq = 1;
        if ($latest && preg_match('/-(\d+)$/', $latest, $matches)) {
            $seq = ((int) $matches[1]) + 1;
        }

        return sprintf('%s-%d-%05d', $prefix, $year, $seq);
    }
}
