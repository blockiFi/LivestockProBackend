<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FeedingSchedule;
use App\Models\FeedingScheduleItem;
use App\Models\PoultryType;
use App\Models\PoultryFeedType;

class EnhancedFeedingScheduleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $poultryTypes = PoultryType::all();

        foreach ($poultryTypes as $poultryType) {
            $this->createFeedingSchedules($poultryType);
        }
    }

    /**
     * Convert feeding times array to proper format with percentages
     */
    private function formatFeedingTimes($times)
    {
        $count = count($times);
        $percentage = round(100 / $count, 2);
        $formatted = [];
        
        foreach ($times as $index => $time) {
            // Adjust last percentage to ensure total is 100%
            $percentageValue = ($index === $count - 1) 
                ? round(100 - ($percentage * ($count - 1)), 2)
                : $percentage;
                
            $formatted[] = [
                'time' => $time,
                'percentage' => $percentageValue
            ];
        }
        
        return $formatted;
    }

    /**
     * Create feeding schedules for a poultry type
     */
    private function createFeedingSchedules($poultryType)
    {
        $feedingSchedules = $this->getFeedingSchedulesForType($poultryType->name, $poultryType->id);
        
        foreach ($feedingSchedules as $scheduleData) {
            $schedule = FeedingSchedule::create([
                'title' => $scheduleData['title'],
                'description' => $scheduleData['description'],
                'start_date' => $scheduleData['start_date'],
                'end_date' => $scheduleData['end_date'],
                'type' => 'default',
            ]);

            // Create schedule items for this feeding schedule
            foreach ($scheduleData['items'] as $itemData) {
                // Get feed type
                $feedType = PoultryFeedType::where('name', $itemData['feed_type_name'])
                    ->where('poultry_type_id', $poultryType->id)
                    ->first();

                if ($feedType) {
                    // Format feeding_times if it's an array of strings
                    $feedingTimes = $itemData['feeding_times'];
                    if (isset($feedingTimes[0]) && is_string($feedingTimes[0])) {
                        $feedingTimes = $this->formatFeedingTimes($feedingTimes);
                    }

                    FeedingScheduleItem::create([
                        'feeding_schedule_id' => $schedule->id,
                        'feed_type_id' => $feedType->id,
                        'feeding_day' => $itemData['feeding_day'],
                        'quantity' => $itemData['quantity'],
                        'feeding_times' => $feedingTimes,
                    ]);
                }
            }
        }
    }

    /**
     * Get feeding schedule data for each poultry type
     */
    private function getFeedingSchedulesForType($typeName, $poultryTypeId)
    {
        $schedules = [
            'Broiler' => [
                [
                    'title' => 'Broiler Standard 6-Week Program',
                    'description' => 'Complete feeding program for broilers from day 1 to market age (42 days). Three-phase feeding: Starter (0-14 days), Grower (15-28 days), Finisher (29-42 days). Optimized for feed conversion ratio and uniform growth.',
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addDays(42)->toDateString(),
                    'items' => $this->getBroilerStandardItems(),
                ],
                [
                    'title' => 'Broiler Fast Growth Program',
                    'description' => 'Intensive feeding schedule for rapid broiler growth targeting 35-day market weight. High-energy formulation with increased protein levels in starter phase. Designed for modern, fast-growing broiler genetics.',
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addDays(35)->toDateString(),
                    'items' => $this->getBroilerFastGrowthItems(),
                ],
                [
                    'title' => 'Broiler Extended Growth Program',
                    'description' => 'Traditional feeding program for broilers grown to heavier market weights (49 days). Four-phase system including pre-starter phase. Suitable for slower-growing breeds or larger market weight requirements.',
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addDays(49)->toDateString(),
                    'items' => $this->getBroilerExtendedItems(),
                ],
                [
                    'title' => 'Broiler Organic/Free-Range Program',
                    'description' => 'Specialized feeding schedule for organic or free-range broiler production. Slower growth rate (56 days) with organic-certified feeds. Allows for outdoor access and natural foraging behavior while meeting organic standards.',
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addDays(56)->toDateString(),
                    'items' => $this->getBroilerOrganicItems(),
                ],
            ],
            'Layer' => [
                [
                    'title' => 'Layer Standard Production Schedule',
                    'description' => 'Comprehensive feeding program for commercial layers from day 1 through peak production. Covers starter (0-6 weeks), grower (7-16 weeks), developer (17-18 weeks), and layer phases. Optimized for egg production and shell quality.',
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addDays(560)->toDateString(), // ~80 weeks
                    'items' => $this->getLayerStandardItems(),
                ],
                [
                    'title' => 'Layer Premium Production Program',
                    'description' => 'Enhanced feeding schedule for high-performance layer strains. Precision nutrition with phase feeding during lay cycle to match nutrient requirements to production curve. Includes pre-lay preparation phase for optimal start.',
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addDays(560)->toDateString(),
                    'items' => $this->getLayerPremiumItems(),
                ],
                [
                    'title' => 'Layer Free-Range Program',
                    'description' => 'Feeding schedule adapted for free-range layer systems. Accounts for nutrient intake from foraging while ensuring complete nutrition. Modified feeding times and quantities to accommodate outdoor access periods.',
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addDays(560)->toDateString(),
                    'items' => $this->getLayerFreeRangeItems(),
                ],
                [
                    'title' => 'Layer Extended Production Cycle',
                    'description' => 'Long-cycle feeding program for layers managed through molt and second production cycle. Includes controlled molt feeding phase and post-molt nutrition for extended productive life up to 100+ weeks.',
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addDays(700)->toDateString(), // ~100 weeks
                    'items' => $this->getLayerExtendedItems(),
                ],
            ],
            'Cockerel' => [
                [
                    'title' => 'Cockerel Standard Meat Production',
                    'description' => 'Feeding program optimized for male birds raised for meat. Higher protein levels than mixed-sex feeding to support greater muscle development. 49-day program to heavier market weights.',
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addDays(49)->toDateString(),
                    'items' => $this->getCockerelStandardItems(),
                ],
                [
                    'title' => 'Cockerel Intensive Growth Program',
                    'description' => 'High-energy feeding schedule for maximum cockerel growth rate. Supports aggressive growth potential of male birds with specialized nutrient ratios. Targets 42-day market readiness.',
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addDays(42)->toDateString(),
                    'items' => $this->getCockerelIntensiveItems(),
                ],
                [
                    'title' => 'Cockerel Breeder Development',
                    'description' => 'Specialized feeding for cockerels selected as breeding stock. Controlled growth rate to prevent obesity while ensuring proper skeletal and reproductive development. Extended to 22 weeks.',
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addDays(154)->toDateString(),
                    'items' => $this->getCockerelBreederItems(),
                ],
                [
                    'title' => 'Cockerel Free-Range Program',
                    'description' => 'Feeding schedule for free-range cockerel production. Balanced nutrition accounting for outdoor foraging. Slower growth rate (63 days) suitable for alternative production systems.',
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addDays(63)->toDateString(),
                    'items' => $this->getCockerelFreeRangeItems(),
                ],
            ],
            'Pullet' => [
                [
                    'title' => 'Pullet Standard Rearing Program',
                    'description' => 'Complete rearing program for layer replacement pullets from day 1 to point of lay (18 weeks). Three-phase system: Starter, Grower, and Developer. Optimized for proper body weight curve and uniform development.',
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addDays(126)->toDateString(),
                    'items' => $this->getPulletStandardItems(),
                ],
                [
                    'title' => 'Pullet Precision Rearing Schedule',
                    'description' => 'Advanced feeding program using precision nutrition for optimal pullet development. Body weight monitoring with feed adjustments to maintain target growth curve. Ensures uniform flock ready for production.',
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addDays(126)->toDateString(),
                    'items' => $this->getPulletPrecisionItems(),
                ],
                [
                    'title' => 'Pullet Organic Certification Program',
                    'description' => 'Organic-certified feeding schedule for pullet rearing. Uses only approved organic feeds and supplements. Slightly extended rearing period (20 weeks) to accommodate slower growth with organic nutrition.',
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addDays(140)->toDateString(),
                    'items' => $this->getPulletOrganicItems(),
                ],
                [
                    'title' => 'Pullet Breeder Replacement Program',
                    'description' => 'Specialized rearing program for pullets destined for breeding flocks. Controlled nutrition to achieve optimal body weight and composition for breeding. Extended development period to 22 weeks.',
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addDays(154)->toDateString(),
                    'items' => $this->getPulletBreederItems(),
                ],
            ],
            'Dual Purpose' => [
                [
                    'title' => 'Dual Purpose Balanced Program',
                    'description' => 'Versatile feeding schedule for dual-purpose breeds. Balanced nutrition supporting both meat production potential and future egg laying. Moderate growth rate over 20 weeks to point of lay.',
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addDays(140)->toDateString(),
                    'items' => $this->getDualPurposeBalancedItems(),
                ],
                [
                    'title' => 'Dual Purpose Homestead Program',
                    'description' => 'Simplified feeding program for small-scale dual-purpose flocks. Flexible schedule accommodating table scraps and forage while ensuring complete nutrition. Suitable for backyard operations.',
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addDays(140)->toDateString(),
                    'items' => $this->getDualPurposeHomesteadItems(),
                ],
                [
                    'title' => 'Dual Purpose Heritage Breed Program',
                    'description' => 'Feeding schedule tailored for heritage dual-purpose breeds. Respects slower maturation rate (24 weeks) and different nutrient requirements. Emphasizes feed quality over quantity.',
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addDays(168)->toDateString(),
                    'items' => $this->getDualPurposeHeritageItems(),
                ],
                [
                    'title' => 'Dual Purpose Commercial Program',
                    'description' => 'Optimized feeding for commercial dual-purpose operations. Maximizes efficiency for both meat and egg production. Includes early culling option for meat birds and laying program for females.',
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addDays(560)->toDateString(),
                    'items' => $this->getDualPurposeCommercialItems(),
                ],
            ],
        ];

        return $schedules[$typeName] ?? [];
    }

    // Broiler feeding schedule items
    private function getBroilerStandardItems()
    {
        $items = [];
        // Starter phase: Day 1-14 (40-50g per bird per day, increasing)
        for ($day = 1; $day <= 14; $day++) {
            $quantity = 40 + ($day * 0.7); // Gradually increasing
            $items[] = [
                'feed_type_name' => 'Starter',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['06:00', '10:00', '14:00', '18:00'],
            ];
        }
        // Grower phase: Day 15-28 (60-90g per bird per day)
        for ($day = 15; $day <= 28; $day++) {
            $quantity = 60 + (($day - 15) * 2);
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['06:00', '11:00', '16:00', '20:00'],
            ];
        }
        // Finisher phase: Day 29-42 (95-130g per bird per day)
        for ($day = 29; $day <= 42; $day++) {
            $quantity = 95 + (($day - 29) * 2.5);
            $items[] = [
                'feed_type_name' => 'Finisher',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['06:00', '12:00', '18:00'],
            ];
        }
        return $items;
    }

    private function getBroilerFastGrowthItems()
    {
        $items = [];
        // Starter: Day 1-10 (higher initial protein)
        for ($day = 1; $day <= 10; $day++) {
            $quantity = 45 + ($day * 1);
            $items[] = [
                'feed_type_name' => 'Starter',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['05:00', '09:00', '13:00', '17:00', '21:00'],
            ];
        }
        // Grower: Day 11-24
        for ($day = 11; $day <= 24; $day++) {
            $quantity = 60 + (($day - 11) * 2.5);
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['05:00', '10:00', '15:00', '20:00'],
            ];
        }
        // Finisher: Day 25-35
        for ($day = 25; $day <= 35; $day++) {
            $quantity = 100 + (($day - 25) * 3);
            $items[] = [
                'feed_type_name' => 'Finisher',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['06:00', '12:00', '18:00'],
            ];
        }
        return $items;
    }

    private function getBroilerExtendedItems()
    {
        $items = [];
        // Pre-starter: Day 1-7
        for ($day = 1; $day <= 7; $day++) {
            $quantity = 35 + ($day * 0.5);
            $items[] = [
                'feed_type_name' => 'Starter',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['06:00', '09:00', '12:00', '15:00', '18:00'],
            ];
        }
        // Starter: Day 8-21
        for ($day = 8; $day <= 21; $day++) {
            $quantity = 42 + (($day - 8) * 1);
            $items[] = [
                'feed_type_name' => 'Starter',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['06:00', '10:00', '14:00', '18:00'],
            ];
        }
        // Grower: Day 22-35
        for ($day = 22; $day <= 35; $day++) {
            $quantity = 65 + (($day - 22) * 2);
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['06:00', '11:00', '16:00', '20:00'],
            ];
        }
        // Finisher: Day 36-49
        for ($day = 36; $day <= 49; $day++) {
            $quantity = 100 + (($day - 36) * 2);
            $items[] = [
                'feed_type_name' => 'Finisher',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['06:00', '13:00', '19:00'],
            ];
        }
        return $items;
    }

    private function getBroilerOrganicItems()
    {
        $items = [];
        // Organic starter: Day 1-21
        for ($day = 1; $day <= 21; $day++) {
            $quantity = 35 + ($day * 0.8);
            $items[] = [
                'feed_type_name' => 'Starter',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['07:00', '11:00', '15:00', '19:00'],
            ];
        }
        // Organic grower: Day 22-42
        for ($day = 22; $day <= 42; $day++) {
            $quantity = 55 + (($day - 22) * 1.5);
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['07:00', '12:00', '17:00'],
            ];
        }
        // Organic finisher: Day 43-56
        for ($day = 43; $day <= 56; $day++) {
            $quantity = 90 + (($day - 43) * 1.5);
            $items[] = [
                'feed_type_name' => 'Finisher',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['07:00', '14:00', '19:00'],
            ];
        }
        return $items;
    }

    // Layer feeding schedule items
    private function getLayerStandardItems()
    {
        $items = [];
        // Starter: Day 1-42 (weeks 0-6)
        for ($day = 1; $day <= 42; $day++) {
            $quantity = 20 + ($day * 0.3);
            $items[] = [
                'feed_type_name' => 'Starter',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['07:00', '11:00', '15:00', '18:00'],
            ];
        }
        // Grower: Day 43-112 (weeks 7-16)
        for ($day = 43; $day <= 112; $day++) {
            $quantity = 35 + (($day - 43) * 0.4);
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['07:00', '12:00', '17:00'],
            ];
        }
        // Layer mash: Day 113 onwards (weeks 17+)
        for ($day = 113; $day <= 560; $day++) {
            $quantity = 110 + (($day < 140) ? ($day - 113) * 0.5 : 0); // Ramp up to full production
            $quantity = min($quantity, 125); // Cap at 125g
            $items[] = [
                'feed_type_name' => 'Layer Mash',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['06:00', '14:00'],
            ];
        }
        return $items;
    }

    private function getLayerPremiumItems()
    {
        $items = [];
        // Premium starter: Day 1-42
        for ($day = 1; $day <= 42; $day++) {
            $quantity = 22 + ($day * 0.35);
            $items[] = [
                'feed_type_name' => 'Starter',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['06:30', '10:30', '14:30', '18:30'],
            ];
        }
        // Premium grower: Day 43-112
        for ($day = 43; $day <= 112; $day++) {
            $quantity = 38 + (($day - 43) * 0.45);
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['06:30', '12:00', '17:30'],
            ];
        }
        // Layer production: Day 113+
        for ($day = 113; $day <= 560; $day++) {
            $quantity = 115 + (($day < 140) ? ($day - 113) * 0.6 : 0);
            $quantity = min($quantity, 130);
            $items[] = [
                'feed_type_name' => 'Layer Mash',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['06:00', '14:00'],
            ];
        }
        return $items;
    }

    private function getLayerFreeRangeItems()
    {
        $items = [];
        // Free-range starter: Day 1-42
        for ($day = 1; $day <= 42; $day++) {
            $quantity = 18 + ($day * 0.25); // Less feed due to foraging
            $items[] = [
                'feed_type_name' => 'Starter',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['07:00', '12:00', '17:00'],
            ];
        }
        // Free-range grower: Day 43-112
        for ($day = 43; $day <= 112; $day++) {
            $quantity = 30 + (($day - 43) * 0.35);
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['07:00', '13:00', '18:00'],
            ];
        }
        // Free-range layer: Day 113+
        for ($day = 113; $day <= 560; $day++) {
            $quantity = 95 + (($day < 140) ? ($day - 113) * 0.4 : 0);
            $quantity = min($quantity, 110); // Less than confined due to foraging
            $items[] = [
                'feed_type_name' => 'Layer Mash',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['07:00', '15:00'],
            ];
        }
        return $items;
    }

    private function getLayerExtendedItems()
    {
        $items = [];
        // Standard rearing: Day 1-112 (simplified)
        for ($day = 1; $day <= 42; $day++) {
            $items[] = [
                'feed_type_name' => 'Starter',
                'feeding_day' => $day,
                'quantity' => round(20 + ($day * 0.3), 2),
                'feeding_times' => ['07:00', '11:00', '15:00', '18:00'],
            ];
        }
        for ($day = 43; $day <= 112; $day++) {
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => round(35 + (($day - 43) * 0.4), 2),
                'feeding_times' => ['07:00', '12:00', '17:00'],
            ];
        }
        // First cycle: Day 113-490 (~70 weeks of lay)
        for ($day = 113; $day <= 490; $day++) {
            $quantity = 110 + (($day < 140) ? ($day - 113) * 0.5 : 0);
            $quantity = min($quantity, 125);
            $items[] = [
                'feed_type_name' => 'Layer Mash',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['06:00', '14:00'],
            ];
        }
        // Molt period: Day 491-511 (3 weeks controlled feeding)
        for ($day = 491; $day <= 511; $day++) {
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => 40, // Restricted feeding during molt
                'feeding_times' => [['time' => '08:00', 'percentage' => 100]],
            ];
        }
        // Second cycle: Day 512-700
        for ($day = 512; $day <= 700; $day++) {
            $items[] = [
                'feed_type_name' => 'Layer Mash',
                'feeding_day' => $day,
                'quantity' => 120,
                'feeding_times' => ['06:00', '14:00'],
            ];
        }
        return $items;
    }

    // Cockerel feeding schedule items
    private function getCockerelStandardItems()
    {
        $items = [];
        // Higher protein starter: Day 1-14
        for ($day = 1; $day <= 14; $day++) {
            $quantity = 42 + ($day * 0.8);
            $items[] = [
                'feed_type_name' => 'Starter',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['06:00', '10:00', '14:00', '18:00'],
            ];
        }
        // Grower: Day 15-28
        for ($day = 15; $day <= 28; $day++) {
            $quantity = 65 + (($day - 15) * 2.5);
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['06:00', '11:00', '16:00', '20:00'],
            ];
        }
        // Finisher: Day 29-49
        for ($day = 29; $day <= 49; $day++) {
            $quantity = 100 + (($day - 29) * 2.5);
            $items[] = [
                'feed_type_name' => 'Finisher',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['06:00', '12:00', '18:00'],
            ];
        }
        return $items;
    }

    private function getCockerelIntensiveItems()
    {
        $items = [];
        // Intensive starter: Day 1-10
        for ($day = 1; $day <= 10; $day++) {
            $quantity = 48 + ($day * 1.2);
            $items[] = [
                'feed_type_name' => 'Starter',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['05:00', '09:00', '13:00', '17:00', '21:00'],
            ];
        }
        // Intensive grower: Day 11-24
        for ($day = 11; $day <= 24; $day++) {
            $quantity = 65 + (($day - 11) * 3);
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['05:00', '10:00', '15:00', '20:00'],
            ];
        }
        // Intensive finisher: Day 25-42
        for ($day = 25; $day <= 42; $day++) {
            $quantity = 110 + (($day - 25) * 3);
            $items[] = [
                'feed_type_name' => 'Finisher',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['06:00', '12:00', '18:00'],
            ];
        }
        return $items;
    }

    private function getCockerelBreederItems()
    {
        $items = [];
        // Controlled starter: Day 1-42
        for ($day = 1; $day <= 42; $day++) {
            $quantity = 30 + ($day * 0.4);
            $items[] = [
                'feed_type_name' => 'Starter',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['07:00', '12:00', '17:00'],
            ];
        }
        // Controlled grower: Day 43-112
        for ($day = 43; $day <= 112; $day++) {
            $quantity = 45 + (($day - 43) * 0.5);
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['07:00', '13:00', '18:00'],
            ];
        }
        // Pre-breeder: Day 113-154
        for ($day = 113; $day <= 154; $day++) {
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => 95,
                'feeding_times' => ['07:00', '15:00'],
            ];
        }
        return $items;
    }

    private function getCockerelFreeRangeItems()
    {
        $items = [];
        // Free-range starter: Day 1-21
        for ($day = 1; $day <= 21; $day++) {
            $quantity = 35 + ($day * 0.6);
            $items[] = [
                'feed_type_name' => 'Starter',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['07:00', '12:00', '17:00'],
            ];
        }
        // Free-range grower: Day 22-42
        for ($day = 22; $day <= 42; $day++) {
            $quantity = 55 + (($day - 22) * 1.5);
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['07:00', '13:00', '18:00'],
            ];
        }
        // Free-range finisher: Day 43-63
        for ($day = 43; $day <= 63; $day++) {
            $quantity = 85 + (($day - 43) * 1.5);
            $items[] = [
                'feed_type_name' => 'Finisher',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['07:00', '14:00', '19:00'],
            ];
        }
        return $items;
    }

    // Pullet feeding schedule items
    private function getPulletStandardItems()
    {
        $items = [];
        // Pullet starter: Day 1-42
        for ($day = 1; $day <= 42; $day++) {
            $quantity = 20 + ($day * 0.3);
            $items[] = [
                'feed_type_name' => 'Starter',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['07:00', '11:00', '15:00', '18:00'],
            ];
        }
        // Pullet grower: Day 43-112
        for ($day = 43; $day <= 112; $day++) {
            $quantity = 35 + (($day - 43) * 0.4);
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['07:00', '12:00', '17:00'],
            ];
        }
        // Developer: Day 113-126
        for ($day = 113; $day <= 126; $day++) {
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => 65,
                'feeding_times' => ['07:00', '15:00'],
            ];
        }
        return $items;
    }

    private function getPulletPrecisionItems()
    {
        $items = [];
        // Precision starter: Day 1-42
        for ($day = 1; $day <= 42; $day++) {
            $quantity = 22 + ($day * 0.32);
            $items[] = [
                'feed_type_name' => 'Starter',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['06:30', '10:30', '14:30', '18:30'],
            ];
        }
        // Precision grower: Day 43-112
        for ($day = 43; $day <= 112; $day++) {
            $quantity = 38 + (($day - 43) * 0.42);
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['06:30', '12:00', '17:30'],
            ];
        }
        // Pre-lay developer: Day 113-126
        for ($day = 113; $day <= 126; $day++) {
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => 68,
                'feeding_times' => ['06:30', '15:00'],
            ];
        }
        return $items;
    }

    private function getPulletOrganicItems()
    {
        $items = [];
        // Organic starter: Day 1-49
        for ($day = 1; $day <= 49; $day++) {
            $quantity = 18 + ($day * 0.28);
            $items[] = [
                'feed_type_name' => 'Starter',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['07:00', '12:00', '17:00'],
            ];
        }
        // Organic grower: Day 50-126
        for ($day = 50; $day <= 126; $day++) {
            $quantity = 32 + (($day - 50) * 0.38);
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['07:00', '13:00', '18:00'],
            ];
        }
        // Organic developer: Day 127-140
        for ($day = 127; $day <= 140; $day++) {
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => 62,
                'feeding_times' => ['07:00', '15:00'],
            ];
        }
        return $items;
    }

    private function getPulletBreederItems()
    {
        $items = [];
        // Breeder starter: Day 1-42
        for ($day = 1; $day <= 42; $day++) {
            $quantity = 21 + ($day * 0.3);
            $items[] = [
                'feed_type_name' => 'Starter',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['07:00', '11:00', '15:00', '18:00'],
            ];
        }
        // Breeder grower: Day 43-126
        for ($day = 43; $day <= 126; $day++) {
            $quantity = 36 + (($day - 43) * 0.4);
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['07:00', '12:00', '17:00'],
            ];
        }
        // Breeder developer: Day 127-154
        for ($day = 127; $day <= 154; $day++) {
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => 70,
                'feeding_times' => ['07:00', '15:00'],
            ];
        }
        return $items;
    }

    // Dual Purpose feeding schedule items
    private function getDualPurposeBalancedItems()
    {
        $items = [];
        // Dual purpose starter: Day 1-42
        for ($day = 1; $day <= 42; $day++) {
            $quantity = 25 + ($day * 0.35);
            $items[] = [
                'feed_type_name' => 'Starter',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['07:00', '11:00', '15:00', '18:00'],
            ];
        }
        // Dual purpose grower: Day 43-112
        for ($day = 43; $day <= 112; $day++) {
            $quantity = 40 + (($day - 43) * 0.45);
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['07:00', '12:00', '17:00'],
            ];
        }
        // Developer/finisher: Day 113-140
        for ($day = 113; $day <= 140; $day++) {
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => 75,
                'feeding_times' => ['07:00', '14:00'],
            ];
        }
        return $items;
    }

    private function getDualPurposeHomesteadItems()
    {
        $items = [];
        // Simplified homestead feeding
        for ($day = 1; $day <= 42; $day++) {
            $items[] = [
                'feed_type_name' => 'Starter',
                'feeding_day' => $day,
                'quantity' => round(20 + ($day * 0.3), 2),
                'feeding_times' => ['07:00', '16:00'],
            ];
        }
        for ($day = 43; $day <= 140; $day++) {
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => round(35 + (($day - 43) * 0.4), 2),
                'feeding_times' => ['07:00', '16:00'],
            ];
        }
        return $items;
    }

    private function getDualPurposeHeritageItems()
    {
        $items = [];
        // Heritage starter: Day 1-56
        for ($day = 1; $day <= 56; $day++) {
            $quantity = 22 + ($day * 0.25);
            $items[] = [
                'feed_type_name' => 'Starter',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['07:30', '12:00', '17:00'],
            ];
        }
        // Heritage grower: Day 57-140
        for ($day = 57; $day <= 140; $day++) {
            $quantity = 35 + (($day - 57) * 0.35);
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['07:30', '13:00', '18:00'],
            ];
        }
        // Heritage developer: Day 141-168
        for ($day = 141; $day <= 168; $day++) {
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => 65,
                'feeding_times' => ['07:30', '15:00'],
            ];
        }
        return $items;
    }

    private function getDualPurposeCommercialItems()
    {
        $items = [];
        // Commercial dual starter: Day 1-42
        for ($day = 1; $day <= 42; $day++) {
            $quantity = 28 + ($day * 0.38);
            $items[] = [
                'feed_type_name' => 'Starter',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['06:30', '10:30', '14:30', '18:30'],
            ];
        }
        // Commercial dual grower: Day 43-112
        for ($day = 43; $day <= 112; $day++) {
            $quantity = 42 + (($day - 43) * 0.5);
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => round($quantity, 2),
                'feeding_times' => ['06:30', '12:00', '17:30'],
            ];
        }
        // Pre-lay: Day 113-140
        for ($day = 113; $day <= 140; $day++) {
            $items[] = [
                'feed_type_name' => 'Grower',
                'feeding_day' => $day,
                'quantity' => 80,
                'feeding_times' => ['06:30', '14:00'],
            ];
        }
        // Layer phase for females: Day 141-560
        for ($day = 141; $day <= 560; $day++) {
            $items[] = [
                'feed_type_name' => 'Layer Mash',
                'feeding_day' => $day,
                'quantity' => 115,
                'feeding_times' => ['06:30', '14:30'],
            ];
        }
        return $items;
    }
}
