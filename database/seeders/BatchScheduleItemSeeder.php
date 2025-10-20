<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\BatchSchedule;
use App\Models\BatchScheduleItem;
use App\Models\ScheduleItem;
use Illuminate\Support\Facades\DB;

class BatchScheduleItemSeeder extends Seeder
{
    public function run()
    {
        $batchSchedules = BatchSchedule::all();
        $scheduleItems = ScheduleItem::all();
        $adminMethodIds = DB::table('administration_methods')->pluck('id')->all();
        foreach ($batchSchedules as $batchSchedule) {
            foreach ($scheduleItems->random(2) as $item) {
                DB::table('batch_schedule_items')->insert([
                    'batch_schedule_id' => $batchSchedule->id,
                    'schedule_item_id' => $item->id,
                    'status' => 'scheduled',
                    'scheduled_date' => now()->addDays(rand(1, 30)),
                    'actual_date' => null,
                    'administered_by' => null,
                    'poultry_vaccine_product_id' => null,
                    'vaccine_product_batch_id' => null,
                    'poultry_medication_id' => 1,
                    'dosage' => 1,
                    'quantity' => 10.0,
                    'cost' => 1000.0,
                    'notes' => 'Batch schedule item note',
                    'administration_method_id' => count($adminMethodIds) ? $adminMethodIds[array_rand($adminMethodIds)] : 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
} 