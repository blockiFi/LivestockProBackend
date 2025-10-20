<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\BatchSchedule;
use App\Models\Flock;
use App\Models\Schedule;

class BatchScheduleSeeder extends Seeder
{
    public function run()
    {
        $flocks = Flock::all();
        $schedules = Schedule::all();
        foreach ($flocks as $flock) {
            // Assign a vaccination schedule for this flock's type and farm
            $vaccinationSchedule = $schedules->where('schedule_type', 'vaccination')
                ->where('poultry_type_id', $flock->poultry_type_id)
                ->where('farm_id', $flock->farm_id)
                ->first();
            if ($vaccinationSchedule) {
                BatchSchedule::create([
                    'flock_id' => $flock->id,
                    'farm_id' => $flock->farm_id,
                    'schedule_id' => $vaccinationSchedule->id,
                ]);
            }
            // Assign a medication schedule for this flock's type and farm
            $medicationSchedule = $schedules->where('schedule_type', 'medication')
                ->where('poultry_type_id', $flock->poultry_type_id)
                ->where('farm_id', $flock->farm_id)
                ->first();
            if ($medicationSchedule) {
                BatchSchedule::create([
                    'flock_id' => $flock->id,
                    'farm_id' => $flock->farm_id,
                    'schedule_id' => $medicationSchedule->id,
                ]);
            }
        }
    }
} 