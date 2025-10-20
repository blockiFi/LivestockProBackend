<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryVaccineInventory;
use App\Models\PoultryMedicationInventory;
use App\Models\PoultryFeedType;
use App\Models\PoultryVaccineProduct;
use App\Models\MedicationProduct;
use App\Models\Farm;
use Carbon\Carbon;
use Faker\Factory as Faker;
use Illuminate\Support\Facades\DB;

class EnhancedInventorySeeder extends Seeder
{
    /**
     * Enhanced Inventory Seeder - Simulates realistic inventory management
     * 
     * Creates inventory records that:
     * - Include batch-based stock tracking
     * - Reflect realistic initial stock levels
     * - Include expiration dates and lot numbers
     * - Simulate replenishment cycles
     * - Maintain proper stock levels for operations
     */
    public function run()
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('countries')->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        
        $faker = Faker::create();
        
        // Get all farms and product types
        $farms = Farm::all();
        $feedTypes = PoultryFeedType::all();
        $vaccineProducts = PoultryVaccineProduct::all();
        $medicationProducts = MedicationProduct::all();
        
        if ($farms->isEmpty()) {
            $this->command->warn('No farms found. Please run FarmSeeder first.');
            return;
        }
        
        foreach ($farms as $farm) {
            $this->generateFeedInventory($farm, $feedTypes, $faker);
            $this->generateVaccineInventory($farm, $vaccineProducts, $faker);
            $this->generateMedicationInventory($farm, $medicationProducts, $faker);
        }
        
        $this->command->info('Enhanced inventory seeded successfully with realistic stock management.');
    }
    
    /**
     * Generate feed inventory for a farm
     */
    private function generateFeedInventory($farm, $feedTypes, $faker)
    {
        foreach ($feedTypes as $feedType) {
            // Generate multiple batches for each feed type
            $batchCount = $faker->numberBetween(2, 4);
            
            for ($batch = 1; $batch <= $batchCount; $batch++) {
                $quantity = $this->getFeedBatchQuantity($feedType->name, $faker);
                $unitCost = $this->getFeedUnitCost($feedType->name, $faker);
                $expiryDate = $this->getFeedExpiryDate($faker);
                $lotNumber = $this->generateLotNumber($faker);
                
                PoultryFeedInventory::create([
                    'farm_id' => $farm->id,
                    'poultry_feed_type_id' => $feedType->id,
                    'batch_number' => $lotNumber,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'manufacturer' => $this->getFeedManufacturer($faker),
                    'expiry_date' => $expiryDate,
                ]);
            }
        }
    }
    
    /**
     * Generate vaccine inventory for a farm
     */
    private function generateVaccineInventory($farm, $vaccineProducts, $faker)
    {
        foreach ($vaccineProducts as $vaccineProduct) {
            // Generate 1-2 batches for each vaccine
            $batchCount = $faker->numberBetween(1, 2);
            
            for ($batch = 1; $batch <= $batchCount; $batch++) {
                $quantity = $this->getVaccineBatchQuantity($vaccineProduct->name, $faker);
                $unitCost = $this->getVaccineUnitCost($vaccineProduct->name, $faker);
                $expiryDate = $this->getVaccineExpiryDate($faker);
                $lotNumber = $this->generateLotNumber($faker);
                
                PoultryVaccineInventory::create([
                    'farm_id' => $farm->id,
                    'poultry_vaccine_product_id' => $vaccineProduct->id,
                    'batch_number' => $lotNumber,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'manufacturer' => $this->getVaccineManufacturer($faker),
                    'expiry_date' => $expiryDate,
                    'notes' => $this->generateVaccineInventoryNotes($faker, $vaccineProduct, $quantity),
                ]);
            }
        }
    }
    
    /**
     * Generate medication inventory for a farm
     */
    private function generateMedicationInventory($farm, $medicationProducts, $faker)
    {
        foreach ($medicationProducts as $medicationProduct) {
            // Generate 1-3 batches for each medication
            $batchCount = $faker->numberBetween(1, 3);
            
            for ($batch = 1; $batch <= $batchCount; $batch++) {
                $quantity = $this->getMedicationBatchQuantity($medicationProduct->name, $faker);
                $unitCost = $this->getMedicationUnitCost($medicationProduct->name, $faker);
                $expiryDate = $this->getMedicationExpiryDate($faker);
                $lotNumber = $this->generateLotNumber($faker);
                
                PoultryMedicationInventory::create([
                    'farm_id' => $farm->id,
                    'medication_product_id' => $medicationProduct->id,
                    'batch_number' => $lotNumber,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'manufacturer' => $this->getMedicationManufacturer($faker),
                    'expiry_date' => $expiryDate,
                    'notes' => $this->generateMedicationInventoryNotes($faker, $medicationProduct, $quantity),
                ]);
            }
        }
    }
    
    /**
     * Get feed batch quantity based on feed type
     */
    private function getFeedBatchQuantity($feedType, $faker)
    {
        $quantities = [
            'Starter' => [1000, 2000], // 1-2 tons
            'Grower' => [1500, 3000],  // 1.5-3 tons
            'Finisher' => [2000, 4000], // 2-4 tons
            'Layer' => [2000, 5000],   // 2-5 tons
            'Developer' => [1500, 2500], // 1.5-2.5 tons
        ];
        
        $range = $quantities[$feedType] ?? [1000, 3000];
        return $faker->numberBetween($range[0], $range[1]);
    }
    
    /**
     * Get vaccine batch quantity
     */
    private function getVaccineBatchQuantity($vaccineName, $faker)
    {
        // Vaccines typically come in smaller quantities
        return $faker->numberBetween(100, 1000); // 100-1000 doses
    }
    
    /**
     * Get medication batch quantity
     */
    private function getMedicationBatchQuantity($medicationName, $faker)
    {
        // Medications vary by type
        if (str_contains(strtolower($medicationName), 'antibiotic')) {
            return $faker->numberBetween(50, 200); // 50-200 units
        } elseif (str_contains(strtolower($medicationName), 'vitamin')) {
            return $faker->numberBetween(100, 500); // 100-500 units
        } else {
            return $faker->numberBetween(75, 300); // 75-300 units
        }
    }
    
    /**
     * Get feed unit cost
     */
    private function getFeedUnitCost($feedType, $faker)
    {
        $costs = [
            'Starter' => [2.5, 3.5], // $2.50-$3.50 per kg
            'Grower' => [2.0, 2.8],  // $2.00-$2.80 per kg
            'Finisher' => [1.8, 2.5], // $1.80-$2.50 per kg
            'Layer' => [2.2, 3.0],   // $2.20-$3.00 per kg
            'Developer' => [2.1, 2.9], // $2.10-$2.90 per kg
        ];
        
        $range = $costs[$feedType] ?? [2.0, 3.0];
        return $faker->randomFloat(2, $range[0], $range[1]);
    }
    
    /**
     * Get vaccine unit cost
     */
    private function getVaccineUnitCost($vaccineName, $faker)
    {
        if (str_contains(strtolower($vaccineName), 'marek')) {
            return $faker->randomFloat(2, 15, 25); // $15-$25 per dose
        } elseif (str_contains(strtolower($vaccineName), 'newcastle')) {
            return $faker->randomFloat(2, 5, 10); // $5-$10 per dose
        } else {
            return $faker->randomFloat(2, 8, 15); // $8-$15 per dose
        }
    }
    
    /**
     * Get medication unit cost
     */
    private function getMedicationUnitCost($medicationName, $faker)
    {
        if (str_contains(strtolower($medicationName), 'antibiotic')) {
            return $faker->randomFloat(2, 20, 50); // $20-$50 per unit
        } elseif (str_contains(strtolower($medicationName), 'vitamin')) {
            return $faker->randomFloat(2, 5, 15); // $5-$15 per unit
        } else {
            return $faker->randomFloat(2, 10, 30); // $10-$30 per unit
        }
    }
    
    /**
     * Get feed expiry date
     */
    private function getFeedExpiryDate($faker)
    {
        // Feed typically expires in 6-12 months
        return Carbon::now()->addMonths($faker->numberBetween(6, 12));
    }
    
    /**
     * Get vaccine expiry date
     */
    private function getVaccineExpiryDate($faker)
    {
        // Vaccines typically expire in 12-24 months
        return Carbon::now()->addMonths($faker->numberBetween(12, 24));
    }
    
    /**
     * Get medication expiry date
     */
    private function getMedicationExpiryDate($faker)
    {
        // Medications typically expire in 12-36 months
        return Carbon::now()->addMonths($faker->numberBetween(12, 36));
    }
    
    /**
     * Generate lot number
     */
    private function generateLotNumber($faker)
    {
        $prefix = strtoupper($faker->lexify('???'));
        $year = date('Y');
        $number = $faker->numberBetween(1000, 9999);
        
        return "{$prefix}{$year}{$number}";
    }
    
    /**
     * Get feed manufacturer
     */
    private function getFeedManufacturer($faker)
    {
        $manufacturers = [
            'Purina Animal Nutrition',
            'Cargill Animal Nutrition',
            'ADM Animal Nutrition',
            'Nutreco',
            'Alltech',
            'Trouw Nutrition',
        ];
        
        return $faker->randomElement($manufacturers);
    }
    
    /**
     * Get vaccine manufacturer
     */
    private function getVaccineManufacturer($faker)
    {
        $manufacturers = [
            'Merial',
            'Zoetis',
            'Boehringer Ingelheim',
            'Elanco',
            'Ceva Animal Health',
            'Hipra',
        ];
        
        return $faker->randomElement($manufacturers);
    }
    
    /**
     * Get medication manufacturer
     */
    private function getMedicationManufacturer($faker)
    {
        $manufacturers = [
            'Zoetis',
            'Boehringer Ingelheim',
            'Elanco',
            'Merck Animal Health',
            'Bayer Animal Health',
            'Ceva Animal Health',
        ];
        
        return $faker->randomElement($manufacturers);
    }
    
    /**
     * Get feed supplier
     */
    private function getFeedSupplier($faker)
    {
        $suppliers = [
            'Local Feed Mill',
            'Regional Distributor',
            'National Feed Company',
            'Agricultural Cooperative',
            'Direct from Manufacturer',
        ];
        
        return $faker->randomElement($suppliers);
    }
    
    /**
     * Get vaccine supplier
     */
    private function getVaccineSupplier($faker)
    {
        $suppliers = [
            'Veterinary Supply Co.',
            'Animal Health Distributor',
            'Direct from Manufacturer',
            'Regional Veterinary Supply',
            'National Animal Health',
        ];
        
        return $faker->randomElement($suppliers);
    }
    
    /**
     * Get medication supplier
     */
    private function getMedicationSupplier($faker)
    {
        $suppliers = [
            'Veterinary Supply Co.',
            'Animal Health Distributor',
            'Direct from Manufacturer',
            'Regional Veterinary Supply',
            'National Animal Health',
        ];
        
        return $faker->randomElement($suppliers);
    }
    
    /**
     * Get feed received date
     */
    private function getFeedReceivedDate($faker)
    {
        // Received within last 3 months
        return Carbon::now()->subDays($faker->numberBetween(1, 90));
    }
    
    /**
     * Get vaccine received date
     */
    private function getVaccineReceivedDate($faker)
    {
        // Received within last 6 months
        return Carbon::now()->subDays($faker->numberBetween(1, 180));
    }
    
    /**
     * Get medication received date
     */
    private function getMedicationReceivedDate($faker)
    {
        // Received within last 6 months
        return Carbon::now()->subDays($faker->numberBetween(1, 180));
    }
    
    /**
     * Get storage location
     */
    private function getStorageLocation($faker)
    {
        $locations = [
            'Feed Storage Shed',
            'Vaccine Refrigerator',
            'Medication Cabinet',
            'Warehouse A',
            'Warehouse B',
            'Storage Room 1',
            'Storage Room 2',
        ];
        
        return $faker->randomElement($locations);
    }
    
    /**
     * Generate feed inventory notes
     */
    private function generateFeedInventoryNotes($faker, $feedType, $quantity)
    {
        $notes = [
            "Batch of {$quantity}kg {$feedType->name} feed",
            "Quality feed for optimal bird performance",
            "Properly stored in dry conditions",
            "Regular quality control checks performed",
            "Suitable for all poultry types",
        ];
        
        return $faker->randomElement($notes);
    }
    
    /**
     * Generate vaccine inventory notes
     */
    private function generateVaccineInventoryNotes($faker, $vaccineProduct, $quantity)
    {
        $notes = [
            "Batch of {$quantity} doses of {$vaccineProduct->name}",
            "Properly refrigerated storage maintained",
            "Regular temperature monitoring",
            "Vaccine potency verified",
            "Ready for scheduled vaccination program",
        ];
        
        return $faker->randomElement($notes);
    }
    
    /**
     * Generate medication inventory notes
     */
    private function generateMedicationInventoryNotes($faker, $medicationProduct, $quantity)
    {
        $notes = [
            "Batch of {$quantity} units of {$medicationProduct->name}",
            "Proper storage conditions maintained",
            "Expiry date monitored regularly",
            "Emergency medication stock",
            "Prescription medication - veterinary oversight",
        ];
        
        return $faker->randomElement($notes);
    }
} 