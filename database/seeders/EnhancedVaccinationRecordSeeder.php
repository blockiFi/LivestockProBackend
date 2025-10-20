<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PoultryVaccinationRecord;
use App\Models\Flock;
use App\Models\PoultryVaccineInventory;
use App\Models\PoultryVaccineProduct;
use App\Models\AdministrationMethod;
use Carbon\Carbon;
use Faker\Factory as Faker;

class EnhancedVaccinationRecordSeeder extends Seeder
{
    /**
     * Enhanced Vaccination Record Seeder - Simulates realistic vaccination protocols
     * 
     * Creates vaccination records that:
     * - Follow industry-standard vaccination schedules
     * - Are tied to specific vaccine products and inventory
     * - Reflect realistic timing based on flock age and type
     * - Include proper dosage and administration methods
     * - Track vaccine costs and usage
     */
    public function run()
    {
        $faker = Faker::create();
        
        // Get all flocks and vaccine data
        $flocks = Flock::where('status', 'active')->orWhere('status', 'completed')->get();
        $vaccineInventories = PoultryVaccineInventory::all();
        $vaccineProducts = PoultryVaccineProduct::all();
        $administrationMethods = AdministrationMethod::all();
        
        if ($flocks->isEmpty() || $vaccineProducts->isEmpty()) {
            $this->command->warn('No flocks or vaccine products found. Please run EnhancedFlockSeeder and PoultryVaccineProductSeeder first.');
            return;
        }
        
        foreach ($flocks as $flock) {
            $this->generateVaccinationRecordsForFlock($flock, $vaccineInventories, $vaccineProducts, $administrationMethods, $faker);
        }
        
        $this->command->info('Enhanced vaccination records seeded successfully with realistic protocols.');
    }
    
    /**
     * Generate vaccination records for a specific flock
     */
    private function generateVaccinationRecordsForFlock($flock, $vaccineInventories, $vaccineProducts, $administrationMethods, $faker)
    {
        $startDate = Carbon::parse($flock->arrival_date);
        $endDate = $flock->status === 'completed' 
            ? Carbon::parse($flock->actual_end_date ?? $flock->expected_end_date)
            : Carbon::now();
        
        $poultryType = $flock->poultryType->name;
        $currentQuantity = $flock->quantity;
        
        // Get vaccination schedule for this poultry type
        $vaccinationSchedule = $this->getVaccinationSchedule($poultryType);
        
        foreach ($vaccinationSchedule as $vaccination) {
            $vaccinationDate = $startDate->copy()->addDays($vaccination['age_days']);
            
            // Skip if vaccination date is in the future
            if ($vaccinationDate->gt($endDate)) {
                continue;
            }
            
            // Get appropriate vaccine product
            $vaccineProduct = $this->getVaccineProduct($vaccineProducts, $vaccination['vaccine_type'], $poultryType);
            
            if (!$vaccineProduct) {
                continue;
            }
            
            // Get available inventory
            $availableInventory = $this->getAvailableVaccineInventory($vaccineInventories, $vaccineProduct->id);
            
            if (!$availableInventory) {
                // Simulate inventory replenishment
                $this->simulateVaccineInventoryReplenishment($vaccineInventories, $vaccineProduct->id, $faker);
                $availableInventory = $this->getAvailableVaccineInventory($vaccineInventories, $vaccineProduct->id);
            }
            
            if ($availableInventory) {
                // Calculate dosage based on flock size and vaccine type
                $dosage = $this->calculateVaccineDosage($currentQuantity, $vaccination, $vaccineProduct);
                
                // Ensure we don't exceed available inventory
                $actualDosage = min($dosage, $availableInventory->quantity);
                
                if ($actualDosage > 0) {
                    // Create vaccination record
                    PoultryVaccinationRecord::create([
                        'flock_id' => $flock->id,
                        'farm_id' => $flock->farm_id,
                        'poultry_vaccine_inventory_id' => $availableInventory->id,
                        'poultry_vaccine_id' => $vaccineProduct->poultry_vaccine_id,
                        'date' => $vaccinationDate,
                        'administered_by' => $this->getVeterinarianName($faker),
                        'dosage' => $actualDosage,
                        'dosage_unit' => $vaccineProduct->dosage_unit ?? 'mL',
                        'quantity' => $actualDosage,
                        'cost' => $this->calculateVaccineCost($actualDosage, $vaccineProduct, $faker),
                        'notes' => $this->generateVaccinationNotes($faker, $vaccination, $vaccineProduct),
                        'administration_method_id' => $vaccineProduct->administration_method_id,
                    ]);
                    
                    // Update inventory
                    $availableInventory->update([
                        'quantity' => $availableInventory->quantity - $actualDosage
                    ]);
                }
            }
        }
    }
    
    /**
     * Get vaccination schedule for different poultry types
     */
    private function getVaccinationSchedule($poultryType)
    {
        $schedules = [
            'Layer' => [
                ['age_days' => 1, 'vaccine_type' => 'Marek', 'method' => 'injection'],
                ['age_days' => 7, 'vaccine_type' => 'Newcastle', 'method' => 'drinking_water'],
                ['age_days' => 14, 'vaccine_type' => 'Gumboro', 'method' => 'drinking_water'],
                ['age_days' => 21, 'vaccine_type' => 'Newcastle', 'method' => 'drinking_water'],
                ['age_days' => 28, 'vaccine_type' => 'Gumboro', 'method' => 'drinking_water'],
                ['age_days' => 35, 'vaccine_type' => 'Newcastle', 'method' => 'drinking_water'],
                ['age_days' => 42, 'vaccine_type' => 'Fowl Pox', 'method' => 'wing_web'],
                ['age_days' => 56, 'vaccine_type' => 'Newcastle', 'method' => 'drinking_water'],
                ['age_days' => 70, 'vaccine_type' => 'Infectious Bronchitis', 'method' => 'drinking_water'],
                ['age_days' => 84, 'vaccine_type' => 'Newcastle', 'method' => 'drinking_water'],
                ['age_days' => 98, 'vaccine_type' => 'Infectious Bronchitis', 'method' => 'drinking_water'],
                ['age_days' => 112, 'vaccine_type' => 'Newcastle', 'method' => 'drinking_water'],
                ['age_days' => 126, 'vaccine_type' => 'Infectious Bronchitis', 'method' => 'drinking_water'],
                ['age_days' => 140, 'vaccine_type' => 'Newcastle', 'method' => 'drinking_water'],
                ['age_days' => 154, 'vaccine_type' => 'Infectious Bronchitis', 'method' => 'drinking_water'],
                ['age_days' => 168, 'vaccine_type' => 'Newcastle', 'method' => 'drinking_water'],
            ],
            'Broiler' => [
                ['age_days' => 1, 'vaccine_type' => 'Marek', 'method' => 'injection'],
                ['age_days' => 7, 'vaccine_type' => 'Newcastle', 'method' => 'drinking_water'],
                ['age_days' => 14, 'vaccine_type' => 'Gumboro', 'method' => 'drinking_water'],
                ['age_days' => 21, 'vaccine_type' => 'Newcastle', 'method' => 'drinking_water'],
                ['age_days' => 28, 'vaccine_type' => 'Gumboro', 'method' => 'drinking_water'],
                ['age_days' => 35, 'vaccine_type' => 'Newcastle', 'method' => 'drinking_water'],
            ],
            'Pullet' => [
                ['age_days' => 1, 'vaccine_type' => 'Marek', 'method' => 'injection'],
                ['age_days' => 7, 'vaccine_type' => 'Newcastle', 'method' => 'drinking_water'],
                ['age_days' => 14, 'vaccine_type' => 'Gumboro', 'method' => 'drinking_water'],
                ['age_days' => 21, 'vaccine_type' => 'Newcastle', 'method' => 'drinking_water'],
                ['age_days' => 28, 'vaccine_type' => 'Gumboro', 'method' => 'drinking_water'],
                ['age_days' => 35, 'vaccine_type' => 'Newcastle', 'method' => 'drinking_water'],
                ['age_days' => 42, 'vaccine_type' => 'Fowl Pox', 'method' => 'wing_web'],
                ['age_days' => 56, 'vaccine_type' => 'Newcastle', 'method' => 'drinking_water'],
                ['age_days' => 70, 'vaccine_type' => 'Infectious Bronchitis', 'method' => 'drinking_water'],
                ['age_days' => 84, 'vaccine_type' => 'Newcastle', 'method' => 'drinking_water'],
                ['age_days' => 98, 'vaccine_type' => 'Infectious Bronchitis', 'method' => 'drinking_water'],
                ['age_days' => 112, 'vaccine_type' => 'Newcastle', 'method' => 'drinking_water'],
                ['age_days' => 126, 'vaccine_type' => 'Infectious Bronchitis', 'method' => 'drinking_water'],
            ],
        ];
        
        return $schedules[$poultryType] ?? $schedules['Layer'];
    }
    
    /**
     * Get appropriate vaccine product
     */
    private function getVaccineProduct($vaccineProducts, $vaccineType, $poultryType)
    {
        return $vaccineProducts->first(function ($product) use ($vaccineType, $poultryType) {
            return str_contains(strtolower($product->name), strtolower($vaccineType)) &&
                   str_contains(strtolower($product->name), strtolower($poultryType));
        }) ?? $vaccineProducts->first();
    }
    
    /**
     * Get available vaccine inventory
     */
    private function getAvailableVaccineInventory($vaccineInventories, $vaccineProductId)
    {
        return $vaccineInventories->first(function ($inventory) use ($vaccineProductId) {
            return $inventory->poultry_vaccine_product_id === $vaccineProductId && $inventory->quantity > 0;
        });
    }
    
    /**
     * Simulate vaccine inventory replenishment
     */
    private function simulateVaccineInventoryReplenishment($vaccineInventories, $vaccineProductId, $faker)
    {
        foreach ($vaccineInventories as $inventory) {
            if ($inventory->poultry_vaccine_product_id === $vaccineProductId) {
                $replenishmentQuantity = $faker->numberBetween(100, 500); // 100-500 doses
                $inventory->update([
                    'quantity' => $inventory->quantity + $replenishmentQuantity,
                    'last_restocked' => now(),
                ]);
                break;
            }
        }
    }
    
    /**
     * Calculate vaccine dosage
     */
    private function calculateVaccineDosage($flockSize, $vaccination, $vaccineProduct)
    {
        // Base dosage per bird (varies by vaccine type)
        $dosagePerBird = [
            'Marek' => 0.2, // 0.2 mL per bird
            'Newcastle' => 0.1, // 0.1 mL per bird
            'Gumboro' => 0.1, // 0.1 mL per bird
            'Fowl Pox' => 0.1, // 0.1 mL per bird
            'Infectious Bronchitis' => 0.1, // 0.1 mL per bird
        ];
        
        $baseDosage = $dosagePerBird[$vaccination['vaccine_type']] ?? 0.1;
        
        // Add some variation (±10%)
        $variation = 0.9 + (rand(0, 20) / 100); // 0.9 to 1.1
        
        return $flockSize * $baseDosage * $variation;
    }
    
    /**
     * Calculate vaccine cost
     */
    private function calculateVaccineCost($dosage, $vaccineProduct, $faker)
    {
        // Cost per mL varies by vaccine type
        $costPerMl = [
            'Marek' => [15, 25], // $15-$25 per mL
            'Newcastle' => [5, 10], // $5-$10 per mL
            'Gumboro' => [8, 15], // $8-$15 per mL
            'Fowl Pox' => [10, 18], // $10-$18 per mL
            'Infectious Bronchitis' => [6, 12], // $6-$12 per mL
        ];
        
        $vaccineType = $this->extractVaccineType($vaccineProduct->name);
        $range = $costPerMl[$vaccineType] ?? [10, 20];
        $unitCost = $faker->randomFloat(2, $range[0], $range[1]);
        
        return $dosage * $unitCost;
    }
    
    /**
     * Extract vaccine type from product name
     */
    private function extractVaccineType($productName)
    {
        $vaccineTypes = ['Marek', 'Newcastle', 'Gumboro', 'Fowl Pox', 'Infectious Bronchitis'];
        
        foreach ($vaccineTypes as $type) {
            if (str_contains(strtolower($productName), strtolower($type))) {
                return $type;
            }
        }
        
        return 'Newcastle'; // Default
    }
    
    /**
     * Get veterinarian name
     */
    private function getVeterinarianName($faker)
    {
        $veterinarians = [
            'Dr. Sarah Johnson',
            'Dr. Michael Chen',
            'Dr. Emily Rodriguez',
            'Dr. David Thompson',
            'Dr. Lisa Wang',
            'Dr. James Wilson',
            'Dr. Maria Garcia',
            'Dr. Robert Brown',
        ];
        
        return $faker->randomElement($veterinarians);
    }
    
    /**
     * Generate vaccination notes
     */
    private function generateVaccinationNotes($faker, $vaccination, $vaccineProduct)
    {
        $notes = [
            "Routine {$vaccination['vaccine_type']} vaccination",
            "All birds vaccinated successfully",
            "No adverse reactions observed",
            "Vaccination completed as scheduled",
            "Proper storage and handling maintained",
            "Vaccine administered according to protocol",
        ];
        
        return $faker->randomElement($notes);
    }
} 