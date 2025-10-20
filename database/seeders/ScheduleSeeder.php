<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Schedule;
use App\Models\Farm;
use App\Models\PoultryType;

class ScheduleSeeder extends Seeder
{
    public function run()
    {
        $farms = Farm::all();
        $types = PoultryType::all();
        foreach ($farms as $farm) {
            foreach ($types as $type) {
                // Medication schedule
                Schedule::create([
                    'schedule_type' => 'medication',
                    'poultry_type_id' => $type->id,
                    'type' => 'default',
                    'farm_id' => $farm->id,
                    'name' => $type->name . ' Medication Schedule',
                    'description' => 'Default medication schedule for ' . $type->name,
                ]);
                // Vaccination schedule
                Schedule::create([
                    'schedule_type' => 'vaccination',
                    'poultry_type_id' => $type->id,
                    'type' => 'default',
                    'farm_id' => $farm->id,
                    'name' => $type->name . ' Vaccination Schedule',
                    'description' => 'Default vaccination schedule for ' . $type->name,
                ]);
            }
        }
    }
} 