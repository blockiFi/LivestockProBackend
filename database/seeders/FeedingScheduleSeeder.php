<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FeedingSchedule;
use App\Models\FeedingScheduleItem;

class FeedingScheduleSeeder extends Seeder
{
    public function run(): void
    {
        $broilerStarter = \App\Models\PoultryFeedType::where('name', 'Starter')->whereHas('poultryType', function ($q) {
            $q->where('name', 'Broiler');
        })->first();
        $broilerGrower = \App\Models\PoultryFeedType::where('name', 'Grower')->whereHas('poultryType', function ($q) {
            $q->where('name', 'Broiler');
        })->first();
        $broilerFinisher = \App\Models\PoultryFeedType::where('name', 'Finisher')->whereHas('poultryType', function ($q) {
            $q->where('name', 'Broiler');
        })->first();
        $layerStarter = \App\Models\PoultryFeedType::where('name', 'Starter')->whereHas('poultryType', function ($q) {
            $q->where('name', 'Layer');
        })->first();
        $layerGrower = \App\Models\PoultryFeedType::where('name', 'Grower')->whereHas('poultryType', function ($q) {
            $q->where('name', 'Layer');
        })->first();
        $layerMash = \App\Models\PoultryFeedType::where('name', 'Layer Mash')->whereHas('poultryType', function ($q) {
            $q->where('name', 'Layer');
        })->first();

        $broilerType = \App\Models\PoultryType::where('name', 'Broiler')->first();
        $layerType = \App\Models\PoultryType::where('name', 'Layer')->first();

        if (!$broilerStarter) {
            $broilerStarter = \App\Models\PoultryFeedType::firstOrCreate([
                'name' => 'Starter',
                'poultry_type_id' => $broilerType?->id,
            ], ['description' => 'Feed for chicks', 'type' => 'default']);
        }
        if (!$broilerGrower) {
            $broilerGrower = \App\Models\PoultryFeedType::firstOrCreate([
                'name' => 'Grower',
                'poultry_type_id' => $broilerType?->id,
            ], ['description' => 'Feed for growing birds', 'type' => 'default']);
        }
        if (!$broilerFinisher) {
            $broilerFinisher = \App\Models\PoultryFeedType::firstOrCreate([
                'name' => 'Finisher',
                'poultry_type_id' => $broilerType?->id,
            ], ['description' => 'Feed for finishing broilers', 'type' => 'default']);
        }
        if (!$layerStarter) {
            $layerStarter = \App\Models\PoultryFeedType::firstOrCreate([
                'name' => 'Starter',
                'poultry_type_id' => $layerType?->id,
            ], ['description' => 'Feed for chicks', 'type' => 'default']);
        }
        if (!$layerGrower) {
            $layerGrower = \App\Models\PoultryFeedType::firstOrCreate([
                'name' => 'Grower',
                'poultry_type_id' => $layerType?->id,
            ], ['description' => 'Feed for growing birds', 'type' => 'default']);
        }
        if (!$layerMash) {
            $layerMash = \App\Models\PoultryFeedType::firstOrCreate([
                'name' => 'Layer Mash',
                'poultry_type_id' => $layerType?->id,
            ], ['description' => 'Feed for layers', 'type' => 'default']);
        }

        $twiceDaily = [
            ['time' => '08:00', 'percentage' => 50],
            ['time' => '17:00', 'percentage' => 50],
        ];

        $broilerSchedule = FeedingSchedule::create([
            'title' => 'Broiler Feeding Schedule',
            'description' => 'Flexible day-range feeding schedule for broilers',
            'start_date' => now()->subDays(7)->toDateString(),
            'end_date' => now()->addDays(49)->toDateString(),
            'type' => 'default',
            'poultry_type_id' => $broilerType?->id,
        ]);

        $this->createRange($broilerSchedule->id, $broilerStarter->id, 1, 14, 45, $twiceDaily);
        $this->createRange($broilerSchedule->id, $broilerGrower->id, 15, 35, 75, $twiceDaily);
        $this->createRange($broilerSchedule->id, $broilerFinisher->id, 36, 49, 125, $twiceDaily);

        $layerSchedule = FeedingSchedule::create([
            'title' => 'Layer Feeding Schedule',
            'description' => 'Flexible day-range feeding schedule for layers',
            'start_date' => now()->subDays(7)->toDateString(),
            'end_date' => now()->addDays(504)->toDateString(),
            'type' => 'default',
            'poultry_type_id' => $layerType?->id,
        ]);

        $this->createRange($layerSchedule->id, $layerStarter->id, 1, 21, 25, $twiceDaily);
        $this->createRange($layerSchedule->id, $layerGrower->id, 22, 119, 50, $twiceDaily);
        // Open-ended laying phase from day 120 onward.
        $this->createRange($layerSchedule->id, $layerMash->id, 120, null, 105, $twiceDaily);
    }

    private function createRange(
        int $scheduleId,
        int $feedTypeId,
        int $startDay,
        ?int $endDay,
        float $quantity,
        array $feedingTimes
    ): void {
        FeedingScheduleItem::create([
            'feeding_schedule_id' => $scheduleId,
            'feed_type_id' => $feedTypeId,
            'feeding_times' => $feedingTimes,
            'quantity' => $quantity,
            'start_day' => $startDay,
            'end_day' => $endDay,
            'feeding_day' => $startDay,
        ]);
    }
}
