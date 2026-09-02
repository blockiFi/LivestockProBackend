<?php

namespace Database\Seeders\LayerDemo;

use App\Models\Farm;
use App\Models\FeedingSchedule;
use App\Models\FeedingScheduleItem;
use App\Models\PoultryFeedType;
use App\Models\PoultryMedication;
use App\Models\PoultryType;
use App\Models\PoultryVaccine;
use App\Models\Schedule;
use App\Models\ScheduleItem;
use Illuminate\Database\Seeder;

class LayerDemoScheduleSeeder extends Seeder
{
    public function run(): void
    {
        $farm = Farm::where('name', LayerDemoContext::FARM_NAME)->firstOrFail();
        $layerType = PoultryType::where('name', 'Layer')->firstOrFail();
        $vaccines = PoultryVaccine::pluck('id')->all();
        $medications = PoultryMedication::pluck('id')->all();

        $this->createVaccinationSchedule($farm->id, $layerType->id, $vaccines);
        $this->createMedicationSchedule($farm->id, $layerType->id, $medications);
        $this->createFeedingSchedule($layerType);
    }

    private function createVaccinationSchedule(int $farmId, int $poultryTypeId, array $vaccineIds): void
    {
        $schedule = Schedule::create([
            'schedule_type' => 'vaccination',
            'poultry_type_id' => $poultryTypeId,
            'type' => 'default',
            'name' => 'Layer Pullet to Production Schedule',
            'description' => 'Standard layer vaccination from day 1 through point of lay.',
            'farm_id' => $farmId,
        ]);

        $items = [
            ['name' => "Marek's Disease - Day 1", 'age_days' => 1],
            ['name' => 'IB + ND Day-Old', 'age_days' => 1],
            ['name' => 'IBD First Dose - Day 10', 'age_days' => 10],
            ['name' => 'ND + IB Booster - Week 4', 'age_days' => 28],
            ['name' => 'IBD Booster - Week 5', 'age_days' => 35],
            ['name' => 'Fowl Pox - Week 8', 'age_days' => 56],
            ['name' => 'Egg Drop Syndrome - Week 10', 'age_days' => 70],
            ['name' => 'ND + IB Killed Vaccine - Week 16', 'age_days' => 112],
        ];

        foreach ($items as $item) {
            ScheduleItem::create([
                'schedule_id' => $schedule->id,
                'name' => $item['name'],
                'description' => $item['name'],
                'age_days' => $item['age_days'],
                'poultry_vaccine_id' => ! empty($vaccineIds) ? $vaccineIds[array_rand($vaccineIds)] : null,
                'dose' => 1,
                'dose_unit' => 'ml',
            ]);
        }
    }

    private function createMedicationSchedule(int $farmId, int $poultryTypeId, array $medicationIds): void
    {
        $schedule = Schedule::create([
            'schedule_type' => 'medication',
            'poultry_type_id' => $poultryTypeId,
            'type' => 'default',
            'name' => 'Layer Pullet Development Program',
            'description' => 'Medication schedule for layer pullets through point of lay.',
            'farm_id' => $farmId,
        ]);

        $items = [
            ['name' => 'Chick Starter Vitamins - Day 1', 'age_days' => 1],
            ['name' => 'Coccidiosis Control - Day 14', 'age_days' => 14],
            ['name' => 'Calcium-Phosphorus Balance - Week 6', 'age_days' => 42],
            ['name' => 'Mycoplasma Medication - Week 8', 'age_days' => 56],
            ['name' => 'Pre-Lay Calcium Boost - Week 16', 'age_days' => 112],
        ];

        foreach ($items as $item) {
            ScheduleItem::create([
                'schedule_id' => $schedule->id,
                'name' => $item['name'],
                'description' => $item['name'],
                'age_days' => $item['age_days'],
                'poultry_medication_id' => ! empty($medicationIds) ? $medicationIds[array_rand($medicationIds)] : null,
                'dose' => 1,
                'dose_unit' => 'ml',
            ]);
        }
    }

    private function createFeedingSchedule(PoultryType $layerType): void
    {
        $schedule = FeedingSchedule::create([
            'title' => 'Layer Standard Production Schedule',
            'description' => 'Three-phase layer feeding: Starter, Grower, Layer Mash.',
            'start_date' => LayerDemoContext::arrivalDate()->toDateString(),
            'end_date' => LayerDemoContext::expectedEndDate()->toDateString(),
            'type' => 'default',
            'poultry_type_id' => $layerType->id,
        ]);

        $ranges = [
            ['name' => 'Starter', 'start' => 1, 'end' => 42, 'qty' => 32.0, 'times' => ['07:00', '11:00', '15:00', '18:00']],
            ['name' => 'Grower', 'start' => 43, 'end' => 112, 'qty' => 55.0, 'times' => ['07:00', '12:00', '17:00']],
            ['name' => 'Layer Mash', 'start' => 113, 'end' => 504, 'qty' => 115.0, 'times' => ['06:00', '14:00']],
        ];

        foreach ($ranges as $range) {
            $feedType = PoultryFeedType::where('name', $range['name'])
                ->where('poultry_type_id', $layerType->id)
                ->first();

            if (! $feedType) {
                continue;
            }

            FeedingScheduleItem::create([
                'feeding_schedule_id' => $schedule->id,
                'feed_type_id' => $feedType->id,
                'start_day' => $range['start'],
                'end_day' => $range['end'],
                'feeding_day' => $range['start'],
                'quantity' => $range['qty'],
                'feeding_times' => $this->formatFeedingTimes($range['times']),
            ]);
        }
    }

    /** @param list<string> $times */
    private function formatFeedingTimes(array $times): array
    {
        $count = count($times);
        $percentage = round(100 / $count, 2);
        $formatted = [];

        foreach ($times as $index => $time) {
            $formatted[] = [
                'time' => $time,
                'percentage' => $index === $count - 1
                    ? round(100 - ($percentage * ($count - 1)), 2)
                    : $percentage,
            ];
        }

        return $formatted;
    }
}
