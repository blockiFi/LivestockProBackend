<?php

namespace Database\Seeders\LayerDemo;

use App\Models\AdministrationMethod;
use App\Models\BatchSchedule;
use App\Models\BatchScheduleItem;
use App\Models\Customer;
use App\Models\Farm;
use App\Models\FeedingBatchSchedule;
use App\Models\FeedingBatchScheduleItem;
use App\Models\FeedingSchedule;
use App\Models\Flock;
use App\Models\FlockDailyRecord;
use App\Models\FlockExpenditure;
use App\Models\FlockSale;
use App\Models\FlockStage;
use App\Models\MedicationProduct;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryFeedType;
use App\Models\PoultryFeedUsage;
use App\Models\PoultryFlockEggReport;
use App\Models\PoultryFlockWeightReport;
use App\Models\PoultryHouse;
use App\Models\PoultryMedicationInventory;
use App\Models\PoultryMedicationRecord;
use App\Models\PoultryMortalityReport;
use App\Models\PoultryVaccineInventory;
use App\Models\PoultryType;
use App\Models\PoultryVaccinationRecord;
use App\Models\PoultryVaccineProduct;
use App\Models\SalesRecord;
use App\Models\Schedule;
use App\Models\User;
use App\Services\FeedingScheduleRangeService;
use App\Services\MedVacBatchScheduleItemGenerator;
use Carbon\Carbon;
use Database\Seeders\Concerns\SeedsLayerPerformanceData;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LayerDemoFlockOperationsSeeder extends Seeder
{
    use SeedsLayerPerformanceData;

    private Farm $farm;

    private User $owner;

    private Flock $flock;

    /** @var array<string, int> */
    private array $dailyRecordIdsByDate = [];

    /** @var array<int, array{eggs: int, mortality: int, culls: int, headcount: int}> */
    private array $dailyMetricsByAge = [];

    public function run(): void
    {
        $faker = Faker::create();

        $this->farm = Farm::where('name', LayerDemoContext::FARM_NAME)->firstOrFail();
        $this->owner = User::where('email', 'owner1@poultry.com')->firstOrFail();

        $this->flock = $this->createFlock();
        $this->seedDailyRecords($faker);
        $this->seedReports();
        $this->seedFeedUsage();
        $this->seedMedVacSchedules();
        $this->seedFeedingBatchSchedule();
        $this->seedCustomersAndSales($faker);
        $this->seedManualExpenditures();
        $this->seedCullSales($faker);

        $this->command?->info('Layer demo flock operations seeded for ' . $this->flock->name);
    }

    private function createFlock(): Flock
    {
        $layerType = PoultryType::where('name', 'Layer')->firstOrFail();
        $layingStage = FlockStage::where('name', 'Laying')
            ->where('poultry_type_id', $layerType->id)
            ->firstOrFail();
        $house = PoultryHouse::where('farm_id', $this->farm->id)->firstOrFail();
        $arrival = LayerDemoContext::arrivalDate();

        return Flock::create([
            'name' => 'ISA Brown Batch B001',
            'batch_number' => 'B001',
            'breed' => 'ISA Brown',
            'source' => 'HatchTech Farms',
            'quantity' => LayerDemoContext::FLOCK_QUANTITY,
            'arrival_date' => $arrival,
            'arrival_age_days' => 1,
            'expected_end_date' => LayerDemoContext::expectedEndDate(),
            'notes' => 'Demo layer flock — 400 days on farm, ~8 months into egg production.',
            'status' => 'active',
            'farm_id' => $this->farm->id,
            'house_id' => $house->id,
            'poultry_type_id' => $layerType->id,
            'flock_stage_id' => $layingStage->id,
        ]);
    }

    private function seedDailyRecords(\Faker\Generator $faker): void
    {
        $arrival = Carbon::parse($this->flock->arrival_date);
        $today = Carbon::today();
        $currentDate = $arrival->copy();
        $currentQuantity = $this->flock->quantity;
        $batch = [];
        $now = now();

        while ($currentDate->lte($today)) {
            $ageDays = (int) $arrival->diffInDays($currentDate);
            $metrics = $this->calculateLayerDailyMetrics($ageDays, $currentQuantity, $faker);
            $currentQuantity = max(0, $currentQuantity - $metrics['mortality'] - $metrics['culls']);

            $this->dailyMetricsByAge[$ageDays] = [
                'eggs' => $metrics['eggs_collected'],
                'mortality' => $metrics['mortality'],
                'culls' => $metrics['culls'],
                'headcount' => $currentQuantity,
                'feed_kg' => $metrics['feed_consumed_kg'],
            ];

            $batch[] = [
                'flock_id' => $this->flock->id,
                'farm_id' => $this->farm->id,
                'date' => $currentDate->toDateString(),
                'age_days' => $ageDays + 1,
                'total_birds' => $currentQuantity + $metrics['mortality'] + $metrics['culls'],
                'mortality_count' => $metrics['mortality'],
                'culling_count' => $metrics['culls'],
                'mortality' => $metrics['mortality'],
                'culls' => $metrics['culls'],
                'feed_consumed_kg' => $metrics['feed_consumed_kg'],
                'feed_consumption_kg' => $metrics['feed_consumed_kg'],
                'water_consumed_liters' => $metrics['water_consumed_liters'],
                'water_consumption_liters' => $metrics['water_consumed_liters'],
                'avg_weight_grams' => $metrics['avg_weight_grams'],
                'average_weight_kg' => round($metrics['avg_weight_grams'] / 1000, 2),
                'min_temperature' => $metrics['min_temperature'],
                'max_temperature' => $metrics['max_temperature'],
                'temperature_celsius' => round(($metrics['min_temperature'] + $metrics['max_temperature']) / 2, 1),
                'humidity' => $metrics['humidity'],
                'humidity_percentage' => $metrics['humidity'],
                'light_hours' => $metrics['light_hours'],
                'eggs_collected' => $metrics['eggs_collected'],
                'egg_production_count' => $metrics['eggs_collected'],
                'eggs_broken' => $metrics['eggs_broken'],
                'egg_weight_grams' => $metrics['eggs_collected'] > 0 ? 58.0 : 0,
                'notes' => $this->buildDailyNote($metrics),
                'recorded_by' => $this->owner->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= 100) {
                $this->flushDailyRecords($batch);
                $batch = [];
            }

            $currentDate->addDay();
        }

        if (! empty($batch)) {
            $this->flushDailyRecords($batch);
        }
    }

    /** @param list<array<string, mixed>> $batch */
    private function flushDailyRecords(array $batch): void
    {
        DB::table('flock_daily_records')->insert($batch);

        $records = FlockDailyRecord::where('flock_id', $this->flock->id)
            ->whereIn('date', array_column($batch, 'date'))
            ->get(['id', 'date']);

        foreach ($records as $record) {
            $this->dailyRecordIdsByDate[$record->date->toDateString()] = $record->id;
        }
    }

    /** @param array<string, mixed> $metrics */
    private function buildDailyNote(array $metrics): string
    {
        $parts = [];
        if ($metrics['mortality'] > 0) {
            $parts[] = "Mortality: {$metrics['mortality']} birds";
        }
        if ($metrics['eggs_collected'] > 0) {
            $parts[] = "Egg collection: {$metrics['eggs_collected']} eggs";
        }

        return implode('; ', $parts) ?: 'Routine daily observation.';
    }

    private function seedReports(): void
    {
        $layerTypeId = $this->flock->poultry_type_id;
        $arrival = Carbon::parse($this->flock->arrival_date);
        $weeklyMortality = 0;
        $weekStartAge = 0;
        $weekBirdCount = $this->flock->quantity;

        foreach ($this->dailyMetricsByAge as $ageDays => $metrics) {
            $date = $arrival->copy()->addDays($ageDays);

            if ($metrics['eggs'] > 0) {
                $productionPct = $metrics['headcount'] > 0
                    ? round(($metrics['eggs'] / $metrics['headcount']) * 100, 2)
                    : 0;

                PoultryFlockEggReport::create([
                    'farm_id' => $this->farm->id,
                    'flock_id' => $this->flock->id,
                    'eggs_collected' => $metrics['eggs'],
                    'average_egg_weight' => 58.0,
                    'production_percentage' => $productionPct,
                    'bird_count' => $metrics['headcount'],
                    'notes' => 'Synced from daily record.',
                    'date' => $date,
                    'recorded_by' => $this->owner->id,
                ]);
            }

            $weeklyMortality += $metrics['mortality'];
            $weekBirdCount = $metrics['headcount'];

            if ($ageDays > 0 && ($ageDays + 1) % 7 === 0) {
                PoultryFlockWeightReport::create([
                    'farm_id' => $this->farm->id,
                    'flock_id' => $this->flock->id,
                    'average_weight' => round($this->calculateLayerWeight($ageDays, $this->layerPerformanceCurves()['weight_curve']) / 1000, 2),
                    'min_weight' => 1.4,
                    'max_weight' => 2.1,
                    'number_of_birds' => $weekBirdCount,
                    'sample_size' => min(50, max(10, (int) round($weekBirdCount * 0.05))),
                    'report_date' => $date,
                    'notes' => 'Weekly weight sampling.',
                    'recorded_by' => $this->owner->id,
                ]);

                if ($weeklyMortality > 0) {
                    $pct = $weekBirdCount > 0 ? round(($weeklyMortality / $weekBirdCount) * 100, 2) : 0;
                    PoultryMortalityReport::create([
                        'flock_id' => $this->flock->id,
                        'farm_id' => $this->farm->id,
                        'poultry_type_id' => $layerTypeId,
                        'date' => $date,
                        'mortality_count' => $weeklyMortality,
                        'bird_count' => $weekBirdCount,
                        'mortality_percentage' => $pct,
                        'notes' => 'Weekly mortality summary.',
                        'recorded_by' => $this->owner->id,
                    ]);
                }

                $weeklyMortality = 0;
                $weekStartAge = $ageDays + 1;
            }
        }
    }

    private function seedFeedUsage(): void
    {
        $arrival = Carbon::parse($this->flock->arrival_date);
        $layerTypeId = $this->flock->poultry_type_id;

        foreach ($this->dailyMetricsByAge as $ageDays => $metrics) {
            $feedKg = (float) ($metrics['feed_kg'] ?? 0);
            if ($feedKg <= 0) {
                continue;
            }

            $feedTypeName = $this->layerFeedTypeNameForAge($ageDays + 1);
            $feedType = PoultryFeedType::where('name', $feedTypeName)
                ->where('poultry_type_id', $layerTypeId)
                ->first();

            if (! $feedType) {
                continue;
            }

            $inventory = PoultryFeedInventory::where('farm_id', $this->farm->id)
                ->where('poultry_feed_type_id', $feedType->id)
                ->where('quantity', '>', 0)
                ->orderBy('id')
                ->first();

            if (! $inventory) {
                continue;
            }

            $usage = PoultryFeedUsage::create([
                'farm_id' => $this->farm->id,
                'flock_id' => $this->flock->id,
                'poultry_feed_inventory_id' => $inventory->id,
                'poultry_feed_type_id' => $feedType->id,
                'quantity' => $feedKg,
                'unit_cost' => $inventory->unit_cost,
                'usage_date' => $arrival->copy()->addDays($ageDays),
                'created_by' => $this->owner->id,
            ]);

            FlockExpenditure::recordFromFeedUsage($usage);
        }
    }

    private function seedMedVacSchedules(): void
    {
        $generator = app(MedVacBatchScheduleItemGenerator::class);
        $adminMethod = AdministrationMethod::query()->first();
        $vaccineProduct = PoultryVaccineProduct::query()->first();
        $vaccineInventory = $vaccineProduct
            ? PoultryVaccineInventory::where('poultry_vaccine_product_id', $vaccineProduct->id)->first()
            : null;
        $medication = \App\Models\PoultryMedication::query()->first();
        $medicationProduct = MedicationProduct::query()->first();

        foreach (['vaccination', 'medication'] as $type) {
            $schedule = Schedule::where('farm_id', $this->farm->id)
                ->where('schedule_type', $type)
                ->where('poultry_type_id', $this->flock->poultry_type_id)
                ->first();

            if (! $schedule) {
                continue;
            }

            $batchSchedule = BatchSchedule::create([
                'flock_id' => $this->flock->id,
                'farm_id' => $this->farm->id,
                'schedule_id' => $schedule->id,
            ]);

            $generator->generateForBatchSchedule($batchSchedule);

            $items = BatchScheduleItem::where('batch_schedule_id', $batchSchedule->id)->get();

            foreach ($items as $item) {
                $scheduledDate = Carbon::parse($item->scheduled_date);

                if ($scheduledDate->gt(Carbon::today())) {
                    continue;
                }

                $cost = round(150 + ($this->flock->quantity * 0.05), 2);
                $payload = [
                    'status' => 'completed',
                    'actual_date' => $item->scheduled_date,
                    'administered_by' => $this->owner->name,
                    'dosage' => 1,
                    'quantity' => $this->flock->quantity,
                    'cost' => $cost,
                    'notes' => 'Demo schedule implementation.',
                    'administration_method_id' => $adminMethod?->id,
                ];

                if ($type === 'vaccination' && $vaccineProduct) {
                    $payload['poultry_vaccine_product_id'] = $vaccineProduct->id;
                    $payload['vaccine_product_batch_id'] = $vaccineInventory?->id;
                } elseif ($type === 'medication' && $medication) {
                    $payload['poultry_medication_id'] = $medication->id;
                }

                $item->update($payload);
                FlockExpenditure::recordFromBatchScheduleItem($item->fresh(), $type, $this->owner->id);

                if ($type === 'vaccination' && $vaccineProduct) {
                    $record = PoultryVaccinationRecord::create([
                        'farm_id' => $this->farm->id,
                        'flock_id' => $this->flock->id,
                        'poultry_vaccine_id' => $schedule->items()->where('id', $item->schedule_item_id)->value('poultry_vaccine_id'),
                        'poultry_vaccine_inventory_id' => $vaccineInventory?->id,
                        'date' => $item->scheduled_date,
                        'administered_by' => $this->owner->id,
                        'dosage' => 1,
                        'dosage_unit' => 'ml',
                        'quantity' => $this->flock->quantity,
                        'cost' => $cost,
                        'notes' => $item->scheduleItem?->name,
                        'administration_method_id' => $adminMethod?->id,
                    ]);
                    FlockExpenditure::recordFromVaccination($record);
                } elseif ($type === 'medication' && $medication) {
                    $record = PoultryMedicationRecord::create([
                        'farm_id' => $this->farm->id,
                        'flock_id' => $this->flock->id,
                        'poultry_medication_id' => $medication->id,
                        'poultry_medication_inventory_id' => $medicationProduct
                            ? PoultryMedicationInventory::where('medication_product_id', $medicationProduct->id)->value('id')
                            : null,
                        'date' => $item->scheduled_date,
                        'administered_by' => $this->owner->id,
                        'dosage' => 1,
                        'dosage_unit' => 'ml',
                        'quantity' => $this->flock->quantity,
                        'cost' => $cost,
                        'notes' => $item->scheduleItem?->name,
                        'administration_method_id' => $adminMethod?->id,
                    ]);
                    FlockExpenditure::recordFromMedication($record);
                }
            }
        }
    }

    private function seedFeedingBatchSchedule(): void
    {
        $feedingSchedule = FeedingSchedule::where('poultry_type_id', $this->flock->poultry_type_id)
            ->where('title', 'Layer Standard Production Schedule')
            ->with('items')
            ->first();

        if (! $feedingSchedule) {
            return;
        }

        $batchSchedule = FeedingBatchSchedule::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'feeding_schedule_id' => $feedingSchedule->id,
            'status' => 'in_progress',
        ]);

        $rangeService = app(FeedingScheduleRangeService::class);
        $arrival = Carbon::parse($this->flock->arrival_date);
        $today = Carbon::today();
        $batch = [];
        $now = now();

        for ($placementDay = 1; $placementDay <= LayerDemoContext::FLOCK_DAYS; $placementDay++) {
            $scheduleItem = $rangeService->resolveForDay($feedingSchedule, $placementDay);
            if (! $scheduleItem) {
                continue;
            }

            $feedingDate = $arrival->copy()->addDays($placementDay - 1);
            $isPast = $feedingDate->lte($today);
            $status = $isPast ? 'completed' : 'scheduled';

            $batch[] = [
                'feeding_batch_schedule_id' => $batchSchedule->id,
                'feeding_schedule_item_id' => $scheduleItem->id,
                'feeding_date' => $feedingDate->toDateString(),
                'actual_feeding_time' => json_encode($scheduleItem->feeding_times),
                'actual_quantity' => $scheduleItem->quantity,
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($batch, 100) as $chunk) {
            DB::table('feeding_batch_schedule_items')->insert($chunk);
        }
    }

    private function seedCustomersAndSales(\Faker\Generator $faker): void
    {
        $customers = [];
        $names = [
            'Adebayo Foods Ltd', 'Green Basket Market', 'Sunrise Grocers',
            'Mama Kemi Store', 'City Fresh Eggs', 'Golden Harvest Catering',
            'Lekki Breakfast Club', 'Victoria Island Bakers',
        ];

        foreach ($names as $index => $name) {
            $customers[] = Customer::create([
                'farm_id' => $this->farm->id,
                'name' => $name,
                'email' => 'customer' . ($index + 1) . '@demo.ng',
                'phone' => '080' . $faker->numerify('########'),
                'address' => $faker->streetAddress(),
                'city' => 'Lagos',
                'state' => 'Lagos',
                'country_id' => $this->farm->country_id,
            ]);
        }

        $arrival = Carbon::parse($this->flock->arrival_date);
        $startLayingAge = $this->layerPerformanceCurves()['egg_production']['start_laying_age'];

        foreach ($this->dailyMetricsByAge as $ageDays => $metrics) {
            if ($ageDays < $startLayingAge || $metrics['eggs'] <= 0) {
                continue;
            }

            $date = $arrival->copy()->addDays($ageDays);
            if ($date->dayOfWeek === 0 || ! $faker->boolean(45)) {
                continue;
            }

            $maxSale = (int) floor($metrics['eggs'] * 0.8);
            if ($maxSale < 30) {
                continue;
            }

            $quantity = $faker->numberBetween(30, $maxSale);
            $unitPrice = $faker->randomFloat(2, 45, 65);
            $customer = $customers[array_rand($customers)];

            SalesRecord::create([
                'farm_id' => $this->farm->id,
                'flock_id' => $this->flock->id,
                'type' => 'egg',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_amount' => round($quantity * $unitPrice, 2),
                'date' => $date,
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'customer_phone' => $customer->phone,
                'payment_method' => $faker->randomElement(['cash', 'transfer', 'pos']),
                'payment_status' => $faker->randomElement(['paid', 'paid', 'paid', 'partial', 'pending']),
                'notes' => 'Egg tray sale.',
                'created_by' => $this->owner->id,
            ]);
        }
    }

    private function seedManualExpenditures(): void
    {
        $arrival = LayerDemoContext::arrivalDate();

        FlockExpenditure::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'category' => FlockExpenditure::CATEGORY_CHICKS,
            'amount' => 450000,
            'description' => 'Day-old pullet purchase — 1,000 ISA Brown chicks',
            'date' => $arrival,
            'payment_method' => 'transfer',
            'reference_no' => 'CHICK-B001',
            'created_by' => $this->owner->id,
        ]);

        $cursor = $arrival->copy()->addMonth();
        while ($cursor->lte(Carbon::today())) {
            FlockExpenditure::create([
                'farm_id' => $this->farm->id,
                'flock_id' => $this->flock->id,
                'category' => FlockExpenditure::CATEGORY_LABOUR,
                'amount' => 85000,
                'description' => 'Farm labour — ' . $cursor->format('F Y'),
                'date' => $cursor->copy()->day(5),
                'payment_method' => 'cash',
                'created_by' => $this->owner->id,
            ]);

            FlockExpenditure::create([
                'farm_id' => $this->farm->id,
                'flock_id' => $this->flock->id,
                'category' => FlockExpenditure::CATEGORY_UTILITIES,
                'amount' => 35000,
                'description' => 'Electricity & water — ' . $cursor->format('F Y'),
                'date' => $cursor->copy()->day(10),
                'payment_method' => 'transfer',
                'created_by' => $this->owner->id,
            ]);

            if ($cursor->month % 3 === 0) {
                FlockExpenditure::create([
                    'farm_id' => $this->farm->id,
                    'flock_id' => $this->flock->id,
                    'category' => FlockExpenditure::CATEGORY_MAINTENANCE,
                    'amount' => 120000,
                    'description' => 'House maintenance & equipment repair',
                    'date' => $cursor->copy()->day(15),
                    'payment_method' => 'transfer',
                    'created_by' => $this->owner->id,
                ]);
            }

            $cursor->addMonth();
        }
    }

    private function seedCullSales(\Faker\Generator $faker): void
    {
        $cullDayKeys = collect($this->dailyMetricsByAge)
            ->filter(fn ($m) => $m['culls'] > 0)
            ->keys()
            ->shuffle()
            ->take(3)
            ->values();

        if ($cullDayKeys->isEmpty()) {
            return;
        }

        $arrival = Carbon::parse($this->flock->arrival_date);
        $customer = Customer::where('farm_id', $this->farm->id)->first();

        foreach ($cullDayKeys as $ageDays) {
            $metrics = $this->dailyMetricsByAge[$ageDays];
            $date = $arrival->copy()->addDays($ageDays);
            $dateStr = $date->toDateString();
            $dailyRecordId = $this->dailyRecordIdsByDate[$dateStr] ?? null;

            FlockSale::create([
                'farm_id' => $this->farm->id,
                'flock_id' => $this->flock->id,
                'customer_id' => $customer?->id,
                'quantity' => min($metrics['culls'], 5),
                'unit_price' => $faker->randomFloat(2, 800, 1200),
                'total_amount' => round(min($metrics['culls'], 5) * $faker->randomFloat(2, 800, 1200), 2),
                'date' => $date,
                'customer_name' => $customer?->name,
                'customer_phone' => $customer?->phone,
                'notes' => 'Culled birds sold.',
                'daily_record_id' => $dailyRecordId,
                'culls_applied' => min($metrics['culls'], 5),
                'created_by' => $this->owner->id,
            ]);
        }
    }
}
