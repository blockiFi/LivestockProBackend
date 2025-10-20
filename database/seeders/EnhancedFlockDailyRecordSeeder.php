<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FlockDailyRecord;
use App\Models\Flock;
use App\Models\User;
use Carbon\Carbon;
use Faker\Factory as Faker;

class EnhancedFlockDailyRecordSeeder extends Seeder
{
    /**
     * Enhanced Flock Daily Record Seeder - Simulates realistic daily flock performance
     * 
     * Creates daily records with:
     * - Realistic weight gain curves based on poultry type and age
     * - Feed consumption patterns that follow industry standards
     * - Mortality patterns with early mortality spike and gradual decline
     * - Egg production for layers based on age and breed standards
     * - Environmental conditions (temperature, humidity, light hours)
     */
    public function run()
    {
        $faker = Faker::create();
        
        // Get all active flocks and users
        $flocks = Flock::where('status', 'active')->orWhere('status', 'completed')->get();
        $users = User::all();
        
        if ($flocks->isEmpty()) {
            $this->command->warn('No flocks found. Please run EnhancedFlockSeeder first.');
            return;
        }
        
        foreach ($flocks as $flock) {
            $this->generateDailyRecordsForFlock($flock, $users, $faker);
        }
        
        $this->command->info('Enhanced daily records seeded successfully with realistic performance data.');
    }
    
    /**
     * Generate daily records for a specific flock
     */
    private function generateDailyRecordsForFlock($flock, $users, $faker)
    {
        $startDate = Carbon::parse($flock->arrival_date);
        $endDate = $flock->status === 'completed' 
            ? Carbon::parse($flock->actual_end_date ?? $flock->expected_end_date)
            : Carbon::now();
        
        $poultryType = $flock->poultryType->name;
        $initialQuantity = $flock->quantity;
        
        // Define performance curves based on poultry type
        $performanceCurves = $this->getPerformanceCurves($poultryType);
        
        $currentDate = $startDate->copy();
        $currentQuantity = $initialQuantity;
        
        while ($currentDate->lte($endDate)) {
            $ageDays = $startDate->diffInDays($currentDate);
            
            // Skip if age exceeds maximum for this poultry type
            if ($ageDays > $performanceCurves['max_age_days']) {
                break;
            }
            
            // Calculate daily performance metrics
            $metrics = $this->calculateDailyMetrics($ageDays, $currentQuantity, $performanceCurves, $faker);
            
            // Update current quantity based on mortality
            $currentQuantity -= $metrics['mortality'];
            
            // Create daily record
            FlockDailyRecord::create([
                'flock_id' => $flock->id,
                'farm_id' => $flock->farm_id,
                'date' => $currentDate,
                'mortality' => $metrics['mortality'],
                'culls' => $metrics['culls'],
                'feed_consumed_kg' => $metrics['feed_consumed_kg'],
                'water_consumed_liters' => $metrics['water_consumed_liters'],
                'avg_weight_grams' => $metrics['avg_weight_grams'],
                'min_temperature' => $metrics['min_temperature'],
                'max_temperature' => $metrics['max_temperature'],
                'humidity' => $metrics['humidity'],
                'light_hours' => $metrics['light_hours'],
                'eggs_collected' => $metrics['eggs_collected'],
                'eggs_broken' => $metrics['eggs_broken'],
                'notes' => $this->generateDailyNotes($faker, $metrics),
                'recorded_by' => $users->random()->id,
            ]);
            
            $currentDate->addDay();
        }
    }
    
    /**
     * Get performance curves for different poultry types
     */
    private function getPerformanceCurves($poultryType)
    {
        $curves = [
            'Layer' => [
                'max_age_days' => 504, // 72 weeks
                'weight_curve' => [
                    'initial_weight' => 40, // grams at day 1
                    'peak_weight' => 1800, // grams at peak
                    'peak_age_days' => 140, // 20 weeks
                ],
                'feed_curve' => [
                    'initial_feed' => 0.005, // kg per bird per day
                    'peak_feed' => 0.120, // kg per bird per day
                    'peak_age_days' => 140,
                    'laying_feed' => 0.110, // kg per bird per day during laying
                ],
                'mortality_curve' => [
                    'early_mortality_rate' => 0.05, // 5% in first week
                    'weekly_decline' => 0.8, // 20% reduction per week
                    'base_mortality_rate' => 0.001, // 0.1% per day base rate
                ],
                'egg_production' => [
                    'start_laying_age' => 154, // 22 weeks
                    'peak_laying_age' => 210, // 30 weeks
                    'peak_laying_rate' => 0.85, // 85% peak production
                    'decline_rate' => 0.995, // 0.5% decline per day after peak
                ],
            ],
            'Broiler' => [
                'max_age_days' => 56, // 8 weeks
                'weight_curve' => [
                    'initial_weight' => 45, // grams at day 1
                    'peak_weight' => 2500, // grams at 8 weeks
                    'peak_age_days' => 56,
                ],
                'feed_curve' => [
                    'initial_feed' => 0.008, // kg per bird per day
                    'peak_feed' => 0.150, // kg per bird per day
                    'peak_age_days' => 56,
                ],
                'mortality_curve' => [
                    'early_mortality_rate' => 0.03, // 3% in first week
                    'weekly_decline' => 0.7, // 30% reduction per week
                    'base_mortality_rate' => 0.002, // 0.2% per day base rate
                ],
            ],
            'Pullet' => [
                'max_age_days' => 140, // 20 weeks
                'weight_curve' => [
                    'initial_weight' => 40, // grams at day 1
                    'peak_weight' => 1400, // grams at 20 weeks
                    'peak_age_days' => 140,
                ],
                'feed_curve' => [
                    'initial_feed' => 0.005, // kg per bird per day
                    'peak_feed' => 0.080, // kg per bird per day
                    'peak_age_days' => 140,
                ],
                'mortality_curve' => [
                    'early_mortality_rate' => 0.04, // 4% in first week
                    'weekly_decline' => 0.75, // 25% reduction per week
                    'base_mortality_rate' => 0.001, // 0.1% per day base rate
                ],
            ],
        ];
        
        return $curves[$poultryType] ?? $curves['Layer'];
    }
    
    /**
     * Calculate daily performance metrics
     */
    private function calculateDailyMetrics($ageDays, $currentQuantity, $curves, $faker)
    {
        // Calculate weight gain
        $weight = $this->calculateWeight($ageDays, $curves['weight_curve']);
        
        // Calculate feed consumption
        $feedPerBird = $this->calculateFeedConsumption($ageDays, $curves['feed_curve']);
        $totalFeed = $feedPerBird * $currentQuantity;
        
        // Calculate mortality
        $mortalityRate = $this->calculateMortalityRate($ageDays, $curves['mortality_curve']);
        $mortality = round($currentQuantity * $mortalityRate);
        
        // Calculate culls (sick or injured birds)
        $cullRate = $faker->randomFloat(3, 0.0001, 0.001); // 0.01% to 0.1% per day
        $culls = round($currentQuantity * $cullRate);
        
        // Calculate water consumption (typically 2-3x feed consumption)
        $waterRatio = $faker->randomFloat(2, 2.0, 3.0);
        $waterPerBird = $feedPerBird * $waterRatio;
        $totalWater = $waterPerBird * $currentQuantity;
        
        // Calculate egg production for layers
        $eggsCollected = 0;
        $eggsBroken = 0;
        if (isset($curves['egg_production']) && $ageDays >= $curves['egg_production']['start_laying_age']) {
            $layingRate = $this->calculateLayingRate($ageDays, $curves['egg_production']);
            $eggsCollected = round($currentQuantity * $layingRate);
            $eggsBroken = round($eggsCollected * $faker->randomFloat(3, 0.01, 0.03)); // 1-3% breakage
        }
        
        // Environmental conditions
        $minTemp = $faker->randomFloat(1, 18.0, 22.0); // 18-22°C
        $maxTemp = $faker->randomFloat(1, 22.0, 28.0); // 22-28°C
        $humidity = $faker->randomFloat(1, 50.0, 70.0); // 50-70%
        $lightHours = $faker->randomFloat(1, 14.0, 16.0); // 14-16 hours
        
        return [
            'mortality' => $mortality,
            'culls' => $culls,
            'feed_consumed_kg' => round($totalFeed, 2),
            'water_consumed_liters' => round($totalWater, 2),
            'avg_weight_grams' => round($weight),
            'min_temperature' => $minTemp,
            'max_temperature' => $maxTemp,
            'humidity' => $humidity,
            'light_hours' => $lightHours,
            'eggs_collected' => $eggsCollected,
            'eggs_broken' => $eggsBroken,
        ];
    }
    
    /**
     * Calculate weight based on age using sigmoid curve
     */
    private function calculateWeight($ageDays, $weightCurve)
    {
        $initialWeight = $weightCurve['initial_weight'];
        $peakWeight = $weightCurve['peak_weight'];
        $peakAge = $weightCurve['peak_age_days'];
        
        // Sigmoid growth curve
        $growthRate = 0.1; // Controls how fast growth occurs
        $weight = $initialWeight + ($peakWeight - $initialWeight) * 
                 (1 / (1 + exp(-$growthRate * ($ageDays - $peakAge / 2))));
        
        return max($initialWeight, $weight);
    }
    
    /**
     * Calculate feed consumption based on age
     */
    private function calculateFeedConsumption($ageDays, $feedCurve)
    {
        $initialFeed = $feedCurve['initial_feed'];
        $peakFeed = $feedCurve['peak_feed'];
        $peakAge = $feedCurve['peak_age_days'];
        
        // Linear increase to peak, then maintain or slight decline
        if ($ageDays <= $peakAge) {
            $feedPerBird = $initialFeed + ($peakFeed - $initialFeed) * ($ageDays / $peakAge);
        } else {
            // Slight decline after peak for layers, maintain for broilers
            $declineRate = isset($feedCurve['laying_feed']) ? 0.995 : 1.0;
            $feedPerBird = $peakFeed * pow($declineRate, $ageDays - $peakAge);
        }
        
        return max($initialFeed, $feedPerBird);
    }
    
    /**
     * Calculate mortality rate based on age
     */
    private function calculateMortalityRate($ageDays, $mortalityCurve)
    {
        $earlyRate = $mortalityCurve['early_mortality_rate'];
        $weeklyDecline = $mortalityCurve['weekly_decline'];
        $baseRate = $mortalityCurve['base_mortality_rate'];
        
        // High early mortality that declines exponentially
        $earlyMortality = $earlyRate * pow($weeklyDecline, $ageDays / 7);
        
        // Add base mortality rate
        $totalRate = $earlyMortality + $baseRate;
        
        return min($totalRate, 0.05); // Cap at 5% per day
    }
    
    /**
     * Calculate laying rate for layers
     */
    private function calculateLayingRate($ageDays, $eggProduction)
    {
        $startAge = $eggProduction['start_laying_age'];
        $peakAge = $eggProduction['peak_laying_age'];
        $peakRate = $eggProduction['peak_laying_rate'];
        $declineRate = $eggProduction['decline_rate'];
        
        if ($ageDays < $startAge) {
            return 0;
        }
        
        if ($ageDays <= $peakAge) {
            // Linear increase to peak
            $rate = $peakRate * (($ageDays - $startAge) / ($peakAge - $startAge));
        } else {
            // Gradual decline after peak
            $daysSincePeak = $ageDays - $peakAge;
            $rate = $peakRate * pow($declineRate, $daysSincePeak);
        }
        
        return max(0, min($rate, 0.95)); // Between 0 and 95%
    }
    
    /**
     * Generate realistic daily notes
     */
    private function generateDailyNotes($faker, $metrics)
    {
        $notes = [];
        
        if ($metrics['mortality'] > 0) {
            $notes[] = "Mortality: {$metrics['mortality']} birds";
        }
        
        if ($metrics['eggs_collected'] > 0) {
            $notes[] = "Egg collection: {$metrics['eggs_collected']} eggs";
        }
        
        if ($metrics['eggs_broken'] > 0) {
            $notes[] = "Egg breakage: {$metrics['eggs_broken']} eggs";
        }
        
        // Add random observations
        $observations = [
            'Birds appear healthy and active',
            'Feed consumption normal',
            'Water intake satisfactory',
            'Temperature and humidity within range',
            'Ventilation adequate',
            'No health concerns observed',
            'Flock behavior normal',
        ];
        
        if ($faker->boolean(70)) { // 70% chance of adding observation
            $notes[] = $faker->randomElement($observations);
        }
        
        return implode('; ', $notes);
    }
} 