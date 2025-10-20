<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Flock;
use App\Models\Farm;
use App\Models\PoultryHouse;
use App\Models\PoultryType;
use App\Models\FlockStage;
use Carbon\Carbon;
use Faker\Factory as Faker;

class EnhancedFlockSeeder extends Seeder
{
    /**
     * Enhanced Flock Seeder - Simulates 6 months of realistic poultry farm operations
     * 
     * Creates flocks with staggered start dates, proper lifecycle management,
     * and realistic flock progression through different stages.
     */
    public function run()
    {
        $faker = Faker::create();
        
        // Get all farms, houses, and poultry types
        $farms = Farm::all();
        $houses = PoultryHouse::all();
        $poultryTypes = PoultryType::all();
        $flockStages = FlockStage::all();
        
        // Define realistic flock configurations
        $flockConfigs = [
            'Layer' => [
                'stages' => ['Chick', 'Pullet', 'Point of Lay', 'Laying'],
                'batch_interval_weeks' => 3, // New layer batch every 3 weeks
                'quantity_range' => [800, 1200], // Layer flock sizes
                'arrival_age_days' => 1, // Day-old chicks
                'expected_lifespan_weeks' => 72, // 72 weeks production cycle
            ],
            'Broiler' => [
                'stages' => ['Chick', 'Growing'],
                'batch_interval_weeks' => 2, // New broiler batch every 2 weeks
                'quantity_range' => [1000, 1500], // Broiler flock sizes
                'arrival_age_days' => 1, // Day-old chicks
                'expected_lifespan_weeks' => 8, // 8 weeks to market weight
            ],
            'Pullet' => [
                'stages' => ['Chick', 'Growing'],
                'batch_interval_weeks' => 4, // New pullet batch every 4 weeks
                'quantity_range' => [600, 800], // Pullet flock sizes
                'arrival_age_days' => 1, // Day-old chicks
                'expected_lifespan_weeks' => 20, // 20 weeks to point of lay
            ],
        ];
        
        // Calculate start date for 6 months of operations
        $startDate = Carbon::now()->subMonths(6);
        $currentDate = Carbon::now();
        
        // Track batch numbers for each farm and poultry type
        $batchCounters = [];
        
        foreach ($farms as $farm) {
            $farmHouses = $houses->where('farm_id', $farm->id);
            
            foreach ($poultryTypes as $poultryType) {
                $typeName = $poultryType->name;
                
                if (!isset($flockConfigs[$typeName])) {
                    continue; // Skip unsupported poultry types
                }
                
                $config = $flockConfigs[$typeName];
                $batchInterval = $config['batch_interval_weeks'];
                $quantityRange = $config['quantity_range'];
                
                // Initialize batch counter for this farm and type
                $batchKey = $farm->id . '_' . $poultryType->id;
                $batchCounters[$batchKey] = 1;
                
                // Calculate how many batches we can fit in 6 months
                $totalWeeks = 26; // 6 months
                $totalBatches = floor($totalWeeks / $batchInterval);
                
                // Get available houses for this farm
                $availableHouses = $farmHouses->take(2); // Use up to 2 houses per farm
                
                foreach ($availableHouses as $house) {
                    $flockStartDate = $startDate->copy();
                    
                    for ($batch = 1; $batch <= $totalBatches; $batch++) {
                        // Calculate flock start date
                        $flockStartDate = $startDate->copy()->addWeeks(($batch - 1) * $batchInterval);
                        
                        // Skip if this would create a flock that's still active beyond current date
                        $expectedEndDate = $flockStartDate->copy()->addWeeks($config['expected_lifespan_weeks']);
                        if ($expectedEndDate->gt($currentDate)) {
                            continue; // Skip future flocks
                        }
                        
                        // Determine flock status based on current date
                        $flockStatus = $this->determineFlockStatus($flockStartDate, $config['expected_lifespan_weeks']);
                        
                        // Get appropriate flock stage based on current age
                        $currentAgeWeeks = $flockStartDate->diffInWeeks($currentDate);
                        $flockStage = $this->getFlockStageForAge($flockStages, $poultryType, $currentAgeWeeks);
                        
                        // Generate realistic flock data
                        $quantity = $faker->numberBetween($quantityRange[0], $quantityRange[1]);
                        
                        // Calculate expected end date
                        $expectedEndDate = $flockStartDate->copy()->addWeeks($config['expected_lifespan_weeks']);
                        
                        // Create the flock
                        $flock = Flock::create([
                            'name' => $this->generateFlockName($typeName, $batchCounters[$batchKey]),
                            'batch_number' => 'B' . str_pad($batchCounters[$batchKey], 3, '0', STR_PAD_LEFT),
                            'breed' => $this->getRealisticBreed($typeName),
                            'source' => $this->getRealisticSource($faker),
                            'quantity' => $quantity,
                            'arrival_date' => $flockStartDate,
                            'arrival_age_days' => $config['arrival_age_days'],
                            'expected_end_date' => $expectedEndDate,
                            'actual_end_date' => $flockStatus === 'completed' ? $expectedEndDate : null,
                            'notes' => $this->generateFlockNotes($faker, $typeName, $batch),
                            'status' => $flockStatus,
                            'farm_id' => $farm->id,
                            'house_id' => $house->id,
                            'poultry_type_id' => $poultryType->id,
                            'flock_stage_id' => $flockStage ? $flockStage->id : null,
                        ]);
                        
                        $batchCounters[$batchKey]++;
                        
                        // Add some variation to batch intervals (realistic farm operations)
                        if ($faker->boolean(20)) { // 20% chance of slight delay
                            $flockStartDate->addDays($faker->numberBetween(1, 3));
                        }
                    }
                }
            }
        }
        
        $this->command->info('Enhanced flocks seeded successfully with realistic 6-month operations data.');
    }
    
    /**
     * Determine flock status based on start date and expected lifespan
     */
    private function determineFlockStatus($startDate, $expectedLifespanWeeks)
    {
        $currentDate = Carbon::now();
        $expectedEndDate = $startDate->copy()->addWeeks($expectedLifespanWeeks);
        
        if ($currentDate->gt($expectedEndDate)) {
            return 'completed';
        } elseif ($startDate->gt($currentDate)) {
            return 'pending';
        } else {
            return 'active';
        }
    }
    
    /**
     * Get appropriate flock stage based on current age
     */
    private function getFlockStageForAge($flockStages, $poultryType, $currentAgeWeeks)
    {
        $currentAgeDays = $currentAgeWeeks * 7;
        
        foreach ($flockStages as $stage) {
            if ($stage->poultry_type_id === $poultryType->id) {
                $fromAge = $stage->from_age ?? 0;
                $toAge = $stage->to_age ?? 999;
                
                if ($currentAgeDays >= $fromAge && $currentAgeDays <= $toAge) {
                    return $stage;
                }
            }
        }
        
        return $flockStages->first(); // Fallback
    }
    
    /**
     * Generate realistic flock name
     */
    private function generateFlockName($typeName, $batchNumber)
    {
        $typeAbbreviation = substr($typeName, 0, 3);
        return $typeAbbreviation . '-' . str_pad($batchNumber, 3, '0', STR_PAD_LEFT);
    }
    
    /**
     * Get realistic breed for poultry type
     */
    private function getRealisticBreed($typeName)
    {
        $breeds = [
            'Layer' => ['Lohmann Brown', 'Hy-Line Brown', 'ISA Brown', 'Bovans Brown'],
            'Broiler' => ['Cobb 500', 'Ross 308', 'Hubbard Classic', 'Aviagen'],
            'Pullet' => ['Lohmann Brown', 'Hy-Line Brown', 'ISA Brown'],
        ];
        
        return $breeds[$typeName][array_rand($breeds[$typeName])] ?? 'Commercial';
    }
    
    /**
     * Get realistic source
     */
    private function getRealisticSource($faker)
    {
        $sources = [
            'Local Hatchery',
            'Commercial Hatchery',
            'Breeder Farm',
            'Certified Supplier',
        ];
        
        return $faker->randomElement($sources);
    }
    
    /**
     * Generate realistic flock notes
     */
    private function generateFlockNotes($faker, $typeName, $batch)
    {
        $notes = [
            "Batch {$batch} - {$typeName} flock",
            "Standard management protocol applied",
            "Regular health monitoring in place",
            "Feed and water systems checked daily",
            "Ventilation and temperature controlled",
        ];
        
        return $faker->randomElement($notes);
    }
} 