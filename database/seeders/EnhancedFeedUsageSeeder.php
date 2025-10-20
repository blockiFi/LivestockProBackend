<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PoultryFeedUsage;
use App\Models\Flock;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryFeedType;
use App\Models\Country;
use App\Models\Schedule;
use App\Models\ScheduleItem;
use Carbon\Carbon;
use Faker\Factory as Faker;

class EnhancedFeedUsageSeeder extends Seeder
{
    /**
     * Enhanced Feed Usage Seeder - Simulates realistic feed consumption patterns
     * 
     * Creates feed usage records that:
     * - Follow scheduled feeding activities
     * - Reflect realistic feed consumption based on flock age and type
     * - Properly manage inventory levels
     * - Include batch-based stock tracking
     * - Simulate replenishment events
     */
    public function run()
    {
        $faker = Faker::create();
        
        // Get all flocks, feed inventories, and countries
        $flocks = Flock::where('status', 'active')->orWhere('status', 'completed')->get();
        $feedInventories = PoultryFeedInventory::all();
        $feedTypes = PoultryFeedType::all();
        $countries = Country::all();
        
        if ($flocks->isEmpty() || $feedInventories->isEmpty()) {
            $this->command->warn('No flocks or feed inventories found. Please run EnhancedFlockSeeder and PoultryFeedInventorySeeder first.');
            return;
        }
        
        // Track inventory levels for realistic stock management
        $inventoryLevels = [];
        foreach ($feedInventories as $inventory) {
            $inventoryLevels[$inventory->id] = $inventory->quantity;
        }
        
        foreach ($flocks as $flock) {
            $this->generateFeedUsageForFlock($flock, $feedInventories, $feedTypes, $countries, $inventoryLevels, $faker);
        }
        
        $this->command->info('Enhanced feed usage seeded successfully with realistic consumption patterns.');
    }
    
    /**
     * Generate feed usage for a specific flock
     */
    private function generateFeedUsageForFlock($flock, $feedInventories, $feedTypes, $countries, &$inventoryLevels, $faker)
    {
        $startDate = Carbon::parse($flock->arrival_date);
        $endDate = $flock->status === 'completed' 
            ? Carbon::parse($flock->actual_end_date ?? $flock->expected_end_date)
            : Carbon::now();
        
        $poultryType = $flock->poultryType->name;
        $currentQuantity = $flock->quantity;
        
        // Get appropriate feed types for this poultry type and age
        $feedTypeConfigs = $this->getFeedTypeConfigs($poultryType);
        
        $currentDate = $startDate->copy();
        
        while ($currentDate->lte($endDate)) {
            $ageDays = $startDate->diffInDays($currentDate);
            
            // Determine feed type based on age
            $feedType = $this->getFeedTypeForAge($feedTypes, $poultryType, $ageDays);
            
            if (!$feedType) {
                $currentDate->addDay();
                continue;
            }
            
            // Get available inventory for this feed type
            $availableInventory = $this->getAvailableInventory($feedInventories, $feedType->id, $inventoryLevels);
            
            if (!$availableInventory) {
                // Simulate replenishment if inventory is low
                $this->simulateInventoryReplenishment($feedInventories, $feedType->id, $inventoryLevels, $faker);
                $availableInventory = $this->getAvailableInventory($feedInventories, $feedType->id, $inventoryLevels);
            }
            
            // PATCH: If still no available inventory, skip this day
            if (!$availableInventory) {
                // Optionally log: No inventory available for this feed type on this day
                $currentDate->addDay();
                continue;
            }
            
            if ($availableInventory) {
                // Calculate daily feed consumption based on age and flock size
                $dailyConsumption = $this->calculateDailyFeedConsumption($ageDays, $currentQuantity, $poultryType);
                
                // Ensure we don't exceed available inventory
                $actualConsumption = min($dailyConsumption, $inventoryLevels[$availableInventory->id]);
                
                if ($actualConsumption > 0) {
                    // Only assign countries_id if countries collection is not empty
                    $countryId = $countries->isNotEmpty() ? $countries->random()->id : null;
                    // Create feed usage record
                    PoultryFeedUsage::create([
                        'farm_id' => $flock->farm_id,
                        'flock_id' => $flock->id,
                        'poultry_feed_inventory_id' => $availableInventory->id,
                        'poultry_feed_type_id' => $feedType->id,
                        'quantity' => round($actualConsumption, 2),
                        'unit_cost' => $this->getRealisticUnitCost($feedType, $faker),
                        'usage_date' => $currentDate,
                        'created_by' => $faker->numberBetween(1, 10), // Random user ID
                    ]);
                    
                    // Update inventory level
                    $inventoryLevels[$availableInventory->id] -= $actualConsumption;
                    
                    // Update inventory record
                    $availableInventory->update([
                        'quantity' => $inventoryLevels[$availableInventory->id]
                    ]);
                }
            }
            
            $currentDate->addDay();
        }
    }
    
    /**
     * Get feed type configurations for different poultry types
     */
    private function getFeedTypeConfigs($poultryType)
    {
        $configs = [
            'Layer' => [
                'starter' => ['age_range' => [1, 21], 'feed_type' => 'Starter'],
                'grower' => ['age_range' => [22, 119], 'feed_type' => 'Grower'],
                'developer' => ['age_range' => [120, 153], 'feed_type' => 'Developer'],
                'layer' => ['age_range' => [154, 504], 'feed_type' => 'Layer'],
            ],
            'Broiler' => [
                'starter' => ['age_range' => [1, 14], 'feed_type' => 'Starter'],
                'grower' => ['age_range' => [15, 35], 'feed_type' => 'Grower'],
                'finisher' => ['age_range' => [36, 56], 'feed_type' => 'Finisher'],
            ],
            'Pullet' => [
                'starter' => ['age_range' => [1, 21], 'feed_type' => 'Starter'],
                'grower' => ['age_range' => [22, 119], 'feed_type' => 'Grower'],
                'developer' => ['age_range' => [120, 140], 'feed_type' => 'Developer'],
            ],
        ];
        
        return $configs[$poultryType] ?? $configs['Layer'];
    }
    
    /**
     * Get appropriate feed type for flock age
     */
    private function getFeedTypeForAge($feedTypes, $poultryType, $ageDays)
    {
        $configs = $this->getFeedTypeConfigs($poultryType);
        
        foreach ($configs as $config) {
            if ($ageDays >= $config['age_range'][0] && $ageDays <= $config['age_range'][1]) {
                return $feedTypes->firstWhere('name', $config['feed_type']);
            }
        }
        
        return $feedTypes->first(); // Fallback
    }
    
    /**
     * Get available inventory for feed type
     */
    private function getAvailableInventory($feedInventories, $feedTypeId, $inventoryLevels)
    {
        foreach ($feedInventories as $inventory) {
            if ($inventory->poultry_feed_type_id === $feedTypeId && $inventoryLevels[$inventory->id] > 0) {
                return $inventory;
            }
        }
        
        return null;
    }
    
    /**
     * Simulate inventory replenishment
     */
    private function simulateInventoryReplenishment($feedInventories, $feedTypeId, &$inventoryLevels, $faker)
    {
        foreach ($feedInventories as $inventory) {
            if ($inventory->poultry_feed_type_id === $feedTypeId) {
                // Replenish with realistic batch size
                $replenishmentQuantity = $faker->numberBetween(1000, 5000); // 1-5 tons
                $inventoryLevels[$inventory->id] += $replenishmentQuantity;
                
                // Update inventory record
                $inventory->update([
                    'quantity' => $inventoryLevels[$inventory->id],
                    'last_restocked' => now(),
                ]);
                
                break;
            }
        }
    }
    
    /**
     * Calculate daily feed consumption based on age and flock size
     */
    private function calculateDailyFeedConsumption($ageDays, $flockSize, $poultryType)
    {
        // Base feed consumption curves (kg per bird per day)
        $consumptionCurves = [
            'Layer' => [
                'starter' => [0.005, 0.025], // 5-25g per bird per day
                'grower' => [0.025, 0.080], // 25-80g per bird per day
                'developer' => [0.080, 0.110], // 80-110g per bird per day
                'layer' => [0.110, 0.120], // 110-120g per bird per day
            ],
            'Broiler' => [
                'starter' => [0.008, 0.040], // 8-40g per bird per day
                'grower' => [0.040, 0.120], // 40-120g per bird per day
                'finisher' => [0.120, 0.150], // 120-150g per bird per day
            ],
            'Pullet' => [
                'starter' => [0.005, 0.025], // 5-25g per bird per day
                'grower' => [0.025, 0.070], // 25-70g per bird per day
                'developer' => [0.070, 0.080], // 70-80g per bird per day
            ],
        ];
        
        $curve = $consumptionCurves[$poultryType] ?? $consumptionCurves['Layer'];
        
        // Determine which phase we're in
        $phase = $this->getFeedPhase($poultryType, $ageDays);
        $phaseCurve = $curve[$phase] ?? $curve['starter'];
        
        // Calculate consumption based on age within the phase
        $phaseAge = $this->getPhaseAge($poultryType, $ageDays, $phase);
        $maxPhaseAge = $this->getMaxPhaseAge($poultryType, $phase);
        
        $consumptionPerBird = $phaseCurve[0] + 
            ($phaseCurve[1] - $phaseCurve[0]) * ($phaseAge / $maxPhaseAge);
        
        return $consumptionPerBird * $flockSize;
    }
    
    /**
     * Get feed phase based on age
     */
    private function getFeedPhase($poultryType, $ageDays)
    {
        $phases = [
            'Layer' => [
                'starter' => [1, 21],
                'grower' => [22, 119],
                'developer' => [120, 153],
                'layer' => [154, 504],
            ],
            'Broiler' => [
                'starter' => [1, 14],
                'grower' => [15, 35],
                'finisher' => [36, 56],
            ],
            'Pullet' => [
                'starter' => [1, 21],
                'grower' => [22, 119],
                'developer' => [120, 140],
            ],
        ];
        
        $typePhases = $phases[$poultryType] ?? $phases['Layer'];
        
        foreach ($typePhases as $phase => $range) {
            if ($ageDays >= $range[0] && $ageDays <= $range[1]) {
                return $phase;
            }
        }
        
        return 'starter';
    }
    
    /**
     * Get age within current phase
     */
    private function getPhaseAge($poultryType, $ageDays, $phase)
    {
        $phases = [
            'Layer' => [
                'starter' => [1, 21],
                'grower' => [22, 119],
                'developer' => [120, 153],
                'layer' => [154, 504],
            ],
            'Broiler' => [
                'starter' => [1, 14],
                'grower' => [15, 35],
                'finisher' => [36, 56],
            ],
            'Pullet' => [
                'starter' => [1, 21],
                'grower' => [22, 119],
                'developer' => [120, 140],
            ],
        ];
        
        $typePhases = $phases[$poultryType] ?? $phases['Layer'];
        $range = $typePhases[$phase] ?? [1, 21];
        
        return max(0, $ageDays - $range[0] + 1);
    }
    
    /**
     * Get maximum age for current phase
     */
    private function getMaxPhaseAge($poultryType, $phase)
    {
        $phases = [
            'Layer' => [
                'starter' => 21,
                'grower' => 98,
                'developer' => 34,
                'layer' => 351,
            ],
            'Broiler' => [
                'starter' => 14,
                'grower' => 21,
                'finisher' => 21,
            ],
            'Pullet' => [
                'starter' => 21,
                'grower' => 98,
                'developer' => 21,
            ],
        ];
        
        $typePhases = $phases[$poultryType] ?? $phases['Layer'];
        return $typePhases[$phase] ?? 21;
    }
    
    /**
     * Get realistic unit cost for feed type
     */
    private function getRealisticUnitCost($feedType, $faker)
    {
        $costRanges = [
            'Starter' => [2.5, 3.5], // $2.50-$3.50 per kg
            'Grower' => [2.0, 2.8],  // $2.00-$2.80 per kg
            'Finisher' => [1.8, 2.5], // $1.80-$2.50 per kg
            'Layer' => [2.2, 3.0],   // $2.20-$3.00 per kg
            'Developer' => [2.1, 2.9], // $2.10-$2.90 per kg
        ];
        
        $range = $costRanges[$feedType->name] ?? [2.0, 3.0];
        return $faker->randomFloat(2, $range[0], $range[1]);
    }
} 