<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Schedule;
use App\Models\ScheduleItem;

class ScheduleItemSeeder extends Seeder
{
    public function run()
    {
        $schedules = Schedule::all();
        $vaccineIds = \App\Models\PoultryVaccine::pluck('id')->all();
        $medicationIds = \App\Models\PoultryMedication::pluck('id')->all();
        foreach ($schedules as $schedule) {
            foreach (range(1, 3) as $i) {
                $isVaccine = $i % 2 === 1;
                ScheduleItem::create([
                    'schedule_id' => $schedule->id,
                    'age_days' => 7 * $i,
                    // Set poultry_vaccine_id and poultry_medication_id based on $schedule->schedule_type
                    'poultry_vaccine_id' => $schedule->schedule_type === 'vaccination' && count($vaccineIds) ? $vaccineIds[array_rand($vaccineIds)] : null,
                    'poultry_medication_id' => $schedule->schedule_type === 'medication' && count($medicationIds) ? $medicationIds[array_rand($medicationIds)] : null,
                    'name' => 'Schedule Item ' . $i,
                    'dose' => 1,
                    'dose_unit' => $schedule->schedule_type === 'vaccination' ? 'ml' : ($schedule->schedule_type === 'medication' ? 'mg' : 'unit'),
                    'withdrawal_period_days' => 7,
                    'storage_instructions' => 'Store in a cool, dry place.',
                    'description' => 'Description for schedule item ' . $i,
                ]);
            }
        }
    }
} 