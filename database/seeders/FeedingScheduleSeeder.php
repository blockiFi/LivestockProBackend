<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FeedingSchedule;
use App\Models\FeedingScheduleItem;
use App\Models\FeedingBatchSchedule;
use App\Models\FeedingBatchScheduleItem;
use App\Models\PoultryFeedType;

class FeedingScheduleSeeder extends Seeder
{
    public function run(): void
    {
        // Get feed types for broiler and layer
        $broilerStarter = \App\Models\PoultryFeedType::where('name', 'Starter')->whereHas('poultryType', function($q){ $q->where('name', 'Broiler'); })->first();
        $broilerGrower = \App\Models\PoultryFeedType::where('name', 'Grower')->whereHas('poultryType', function($q){ $q->where('name', 'Broiler'); })->first();
        $broilerFinisher = \App\Models\PoultryFeedType::where('name', 'Finisher')->whereHas('poultryType', function($q){ $q->where('name', 'Broiler'); })->first();
        $layerStarter = \App\Models\PoultryFeedType::where('name', 'Starter')->whereHas('poultryType', function($q){ $q->where('name', 'Layer'); })->first();
        $layerGrower = \App\Models\PoultryFeedType::where('name', 'Grower')->whereHas('poultryType', function($q){ $q->where('name', 'Layer'); })->first();
        $layerMash = \App\Models\PoultryFeedType::where('name', 'Layer Mash')->whereHas('poultryType', function($q){ $q->where('name', 'Layer'); })->first();

        // Ensure broiler feed types exist
        $broilerType = \App\Models\PoultryType::where('name', 'Broiler')->first();
        $layerType = \App\Models\PoultryType::where('name', 'Layer')->first();
        if (!$broilerStarter) {
            $broilerStarter = \App\Models\PoultryFeedType::firstOrCreate([
                'name' => 'Starter',
                'poultry_type_id' => $broilerType ? $broilerType->id : null,
            ], [
                'description' => 'Feed for chicks',
                'type' => 'default',
            ]);
        }
        if (!$broilerGrower) {
            $broilerGrower = \App\Models\PoultryFeedType::firstOrCreate([
                'name' => 'Grower',
                'poultry_type_id' => $broilerType ? $broilerType->id : null,
            ], [
                'description' => 'Feed for growing birds',
                'type' => 'default',
            ]);
        }
        if (!$broilerFinisher) {
            $broilerFinisher = \App\Models\PoultryFeedType::firstOrCreate([
                'name' => 'Finisher',
                'poultry_type_id' => $broilerType ? $broilerType->id : null,
            ], [
                'description' => 'Feed for finishing broilers',
                'type' => 'default',
            ]);
        }
        // Ensure layer feed types exist
        if (!$layerStarter) {
            $layerStarter = \App\Models\PoultryFeedType::firstOrCreate([
                'name' => 'Starter',
                'poultry_type_id' => $layerType ? $layerType->id : null,
            ], [
                'description' => 'Feed for chicks',
                'type' => 'default',
            ]);
        }
        if (!$layerGrower) {
            $layerGrower = \App\Models\PoultryFeedType::firstOrCreate([
                'name' => 'Grower',
                'poultry_type_id' => $layerType ? $layerType->id : null,
            ], [
                'description' => 'Feed for growing birds',
                'type' => 'default',
            ]);
        }
        if (!$layerMash) {
            $layerMash = \App\Models\PoultryFeedType::firstOrCreate([
                'name' => 'Layer Mash',
                'poultry_type_id' => $layerType ? $layerType->id : null,
            ], [
                'description' => 'Feed for layers',
                'type' => 'default',
            ]);
        }

        // Check for required feed types
        if (!$broilerStarter || !$broilerGrower || !$broilerFinisher) {
            throw new \Exception('Missing one or more broiler feed types: Starter, Grower, Finisher. Please seed PoultryFeedTypeSeeder.');
        }
        if (!$layerStarter || !$layerGrower || !$layerMash) {
            throw new \Exception('Missing one or more layer feed types: Starter, Grower, Layer Mash. Please seed PoultryFeedTypeSeeder.');
        }

        // Broiler feeding schedule (8 weeks)
        $broilerSchedule = FeedingSchedule::create([
            'title' => 'Broiler Feeding Schedule',
            'description' => 'Realistic feeding schedule for broilers',
            'start_date' => now()->subDays(7)->toDateString(),
            'end_date' => now()->addDays(49)->toDateString(),
        ]);
        // Broiler Starter: Day 1-14, 40g-50g per bird per day
        foreach (range(1, 14) as $day) {
            FeedingScheduleItem::create([
                'feeding_schedule_id' => $broilerSchedule->id,
                'feed_type_id' => $broilerStarter ? $broilerStarter->id : null,
                'feeding_times' => [
                    ['time' => '08:00', 'percentage' => 50],
                    ['time' => '17:00', 'percentage' => 50],
                ],
                'quantity' => rand(40, 50),
                'feeding_day' => $day,
            ]);
        }
        // Broiler Grower: Day 15-35, 60g-90g per bird per day
        foreach (range(15, 35) as $day) {
            FeedingScheduleItem::create([
                'feeding_schedule_id' => $broilerSchedule->id,
                'feed_type_id' => $broilerGrower ? $broilerGrower->id : null,
                'feeding_times' => [
                    ['time' => '08:00', 'percentage' => 50],
                    ['time' => '17:00', 'percentage' => 50],
                ],
                'quantity' => rand(60, 90),
                'feeding_day' => $day,
            ]);
        }
        // Broiler Finisher: Day 36-49, 100g-150g per bird per day
        foreach (range(36, 49) as $day) {
            FeedingScheduleItem::create([
                'feeding_schedule_id' => $broilerSchedule->id,
                'feed_type_id' => $broilerFinisher ? $broilerFinisher->id : null,
                'feeding_times' => [
                    ['time' => '08:00', 'percentage' => 50],
                    ['time' => '17:00', 'percentage' => 50],
                ],
                'quantity' => rand(100, 150),
                'feeding_day' => $day,
            ]);
        }

        // Layer feeding schedule (72 weeks)
        $layerSchedule = FeedingSchedule::create([
            'title' => 'Layer Feeding Schedule',
            'description' => 'Realistic feeding schedule for layers',
            'start_date' => now()->subDays(7)->toDateString(),
            'end_date' => now()->addDays(504)->toDateString(),
        ]);
        // Layer Starter: Day 1-21, 20g-30g per bird per day
        foreach (range(1, 21) as $day) {
            FeedingScheduleItem::create([
                'feeding_schedule_id' => $layerSchedule->id,
                'feed_type_id' => $layerStarter ? $layerStarter->id : null,
                'feeding_times' => [
                    ['time' => '08:00', 'percentage' => 50],
                    ['time' => '17:00', 'percentage' => 50],
                ],
                'quantity' => rand(20, 30),
                'feeding_day' => $day,
            ]);
        }
        // Layer Grower: Day 22-119, 40g-60g per bird per day
        foreach (range(22, 119) as $day) {
            FeedingScheduleItem::create([
                'feeding_schedule_id' => $layerSchedule->id,
                'feed_type_id' => $layerGrower ? $layerGrower->id : null,
                'feeding_times' => [
                    ['time' => '08:00', 'percentage' => 50],
                    ['time' => '17:00', 'percentage' => 50],
                ],
                'quantity' => rand(40, 60),
                'feeding_day' => $day,
            ]);
        }
        // Layer Mash: Day 120-504, 90g-120g per bird per day
        foreach (range(120, 504) as $day) {
            FeedingScheduleItem::create([
                'feeding_schedule_id' => $layerSchedule->id,
                'feed_type_id' => $layerMash ? $layerMash->id : null,
                'feeding_times' => [
                    ['time' => '08:00', 'percentage' => 50],
                    ['time' => '17:00', 'percentage' => 50],
                ],
                'quantity' => rand(90, 120),
                'feeding_day' => $day,
            ]);
        }
    }
} 