<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SalesRecord;
use App\Models\Customer;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockDailyRecord;
use Carbon\Carbon;
use Faker\Factory as Faker;

class EnhancedSalesRecordSeeder extends Seeder
{
    /**
     * Enhanced Sales Record Seeder - Simulates realistic sales operations
     * 
     * Creates sales records that:
     * - Reflect realistic pricing for different products
     * - Follow seasonal patterns and market demand
     * - Include both regular and bulk sales
     * - Link to actual farm production data
     * - Maintain customer relationship patterns
     */
    public function run()
    {
        $faker = Faker::create();
        
        // Get all farms, customers, and flocks
        $farms = Farm::all();
        $customers = Customer::all();
        $flocks = Flock::all();
        
        if ($farms->isEmpty() || $customers->isEmpty()) {
            $this->command->warn('No farms or customers found. Please run FarmSeeder and CustomerSeeder first.');
            return;
        }
        
        // Calculate date range for 6 months
        $startDate = Carbon::now()->subMonths(6);
        $endDate = Carbon::now();
        
        foreach ($farms as $farm) {
            $this->generateSalesForFarm($farm, $customers, $flocks, $startDate, $endDate, $faker);
        }
        
        $this->command->info('Enhanced sales records seeded successfully with realistic market data.');
    }
    
    /**
     * Generate sales for a specific farm
     */
    private function generateSalesForFarm($farm, $customers, $flocks, $startDate, $endDate, $faker)
    {
        $farmFlocks = $flocks->where('farm_id', $farm->id);
        $farmCustomers = $customers->where('farm_id', $farm->id);
        
        if ($farmCustomers->isEmpty()) {
            return;
        }
        
        $currentDate = $startDate->copy();
        
        while ($currentDate->lte($endDate)) {
            // Generate different types of sales based on day of week and season
            $this->generateDailySales($farm, $farmCustomers, $farmFlocks, $currentDate, $faker);
            
            $currentDate->addDay();
        }
    }
    
    /**
     * Generate daily sales for a farm
     */
    private function generateDailySales($farm, $customers, $flocks, $date, $faker)
    {
        $dayOfWeek = $date->dayOfWeek;
        $isWeekend = in_array($dayOfWeek, [0, 6]); // Sunday = 0, Saturday = 6
        
        // Higher sales probability on weekends and market days
        $salesProbability = $isWeekend ? 0.8 : 0.4;
        
        if ($faker->boolean($salesProbability * 100)) {
            // Generate egg sales (most common)
            $this->generateEggSales($farm, $customers, $flocks, $date, $faker);
        }
        
        // Weekly bulk sales (less frequent)
        if ($date->dayOfWeek === 1 && $faker->boolean(60)) { // Monday
            $this->generateBulkSales($farm, $customers, $flocks, $date, $faker);
        }
        
        // Monthly large orders
        if ($date->day === 15 && $faker->boolean(40)) { // Mid-month
            $this->generateLargeOrders($farm, $customers, $flocks, $date, $faker);
        }
    }
    
    /**
     * Generate egg sales
     */
    private function generateEggSales($farm, $customers, $flocks, $date, $faker)
    {
        // Get layer flocks that are in laying period
        $layerFlocks = $flocks->filter(function ($flock) use ($date) {
            return $flock->poultryType->name === 'Layer' && 
                   $flock->arrival_date && 
                   $flock->arrival_date->diffInDays($date) >= 154; // 22 weeks (start of laying)
        });
        
        if ($layerFlocks->isEmpty()) {
            return;
        }
        
        // Calculate total available eggs for this date
        $totalEggs = 0;
        foreach ($layerFlocks as $flock) {
            $dailyRecord = $flock->dailyRecords()->where('date', $date)->first();
            if ($dailyRecord && $dailyRecord->eggs_collected > 0) {
                $totalEggs += $dailyRecord->eggs_collected;
            }
        }
        
        if ($totalEggs === 0) {
            return;
        }
        
        // Generate multiple sales transactions
        $remainingEggs = $totalEggs;
        $salesCount = $faker->numberBetween(1, 3);
        
        for ($i = 0; $i < $salesCount && $remainingEggs > 0; $i++) {
            $customer = $customers->random();
            $quantity = min($remainingEggs, $faker->numberBetween(50, 200));
            $remainingEggs -= $quantity;
            
            $unitPrice = $this->getEggPrice($date, $faker);
            $totalPrice = $quantity * $unitPrice;
            
            SalesRecord::create([
                'farm_id' => $farm->id,
                'customer_id' => $customer->id,
                'product_type' => 'eggs',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_amount' => $totalPrice,
                'sale_date' => $date,
                'payment_method' => $this->getPaymentMethod($faker),
                'notes' => $this->generateEggSaleNotes($faker, $quantity, $unitPrice),
                'created_by' => $faker->numberBetween(1, 10),
            ]);
        }
    }
    
    /**
     * Generate bulk sales (birds, large quantities)
     */
    private function generateBulkSales($farm, $customers, $flocks, $date, $faker)
    {
        // Get completed broiler flocks
        $broilerFlocks = $flocks->filter(function ($flock) use ($date) {
            return $flock->poultryType->name === 'Broiler' && 
                   $flock->status === 'completed' &&
                   $flock->actual_end_date &&
                   $flock->actual_end_date->diffInDays($date) <= 7; // Within a week of completion
        });
        
        foreach ($broilerFlocks as $flock) {
            if ($faker->boolean(70)) { // 70% chance of bulk sale
                $customer = $customers->random();
                $quantity = $faker->numberBetween(100, 500);
                $unitPrice = $this->getBroilerPrice($date, $faker);
                $totalPrice = $quantity * $unitPrice;
                
                SalesRecord::create([
                    'farm_id' => $farm->id,
                    'customer_id' => $customer->id,
                    'product_type' => 'broilers',
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_amount' => $totalPrice,
                    'sale_date' => $date,
                    'payment_method' => 'bank_transfer',
                    'notes' => "Bulk sale of {$quantity} broilers from flock {$flock->name}",
                    'created_by' => $faker->numberBetween(1, 10),
                ]);
            }
        }
    }
    
    /**
     * Generate large orders (restaurants, wholesalers)
     */
    private function generateLargeOrders($farm, $customers, $flocks, $date, $faker)
    {
        // Large egg orders for restaurants/hotels
        if ($faker->boolean(50)) {
            $customer = $customers->random();
            $quantity = $faker->numberBetween(500, 2000);
            $unitPrice = $this->getEggPrice($date, $faker) * 0.9; // 10% discount for bulk
            $totalPrice = $quantity * $unitPrice;
            
            SalesRecord::create([
                'farm_id' => $farm->id,
                'customer_id' => $customer->id,
                'product_type' => 'eggs',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_amount' => $totalPrice,
                'sale_date' => $date,
                'payment_method' => 'bank_transfer',
                'notes' => "Large order for {$customer->name} - bulk discount applied",
                'created_by' => $faker->numberBetween(1, 10),
            ]);
        }
        
        // Live bird sales (layers, pullets)
        if ($faker->boolean(30)) {
            $customer = $customers->random();
            $quantity = $faker->numberBetween(50, 200);
            $unitPrice = $this->getLiveBirdPrice($date, $faker);
            $totalPrice = $quantity * $unitPrice;
            
            SalesRecord::create([
                'farm_id' => $farm->id,
                'customer_id' => $customer->id,
                'product_type' => 'live_birds',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_amount' => $totalPrice,
                'sale_date' => $date,
                'payment_method' => 'bank_transfer',
                'notes' => "Live bird sale - {$quantity} birds",
                'created_by' => $faker->numberBetween(1, 10),
            ]);
        }
    }
    
    /**
     * Get egg price based on date (seasonal variation)
     */
    private function getEggPrice($date, $faker)
    {
        $month = $date->month;
        
        // Seasonal price variations
        $basePrice = 0.25; // $0.25 per egg base price
        
        $seasonalMultiplier = [
            1 => 1.1,  // January - slightly higher
            2 => 1.0,  // February - normal
            3 => 0.9,  // March - lower
            4 => 0.85, // April - lower
            5 => 0.9,  // May - lower
            6 => 0.95, // June - normal
            7 => 1.0,  // July - normal
            8 => 1.05, // August - slightly higher
            9 => 1.1,  // September - higher
            10 => 1.15, // October - higher
            11 => 1.2,  // November - highest
            12 => 1.15, // December - higher
        ];
        
        $price = $basePrice * $seasonalMultiplier[$month];
        
        // Add some random variation (±10%)
        $variation = 0.9 + (rand(0, 20) / 100);
        
        return round($price * $variation, 2);
    }
    
    /**
     * Get broiler price
     */
    private function getBroilerPrice($date, $faker)
    {
        $basePrice = 8.0; // $8.00 per kg base price
        
        // Add some variation based on market conditions
        $variation = 0.85 + (rand(0, 30) / 100); // ±15% variation
        
        return round($basePrice * $variation, 2);
    }
    
    /**
     * Get live bird price
     */
    private function getLiveBirdPrice($date, $faker)
    {
        $basePrice = 15.0; // $15.00 per bird base price
        
        // Add some variation
        $variation = 0.9 + (rand(0, 20) / 100); // ±10% variation
        
        return round($basePrice * $variation, 2);
    }
    
    /**
     * Get payment method
     */
    private function getPaymentMethod($faker)
    {
        $methods = [
            'cash' => 0.3,      // 30% cash
            'bank_transfer' => 0.4, // 40% bank transfer
            'check' => 0.2,     // 20% check
            'credit_card' => 0.1, // 10% credit card
        ];
        
        $random = $faker->randomFloat(2, 0, 1);
        $cumulative = 0;
        
        foreach ($methods as $method => $probability) {
            $cumulative += $probability;
            if ($random <= $cumulative) {
                return $method;
            }
        }
        
        return 'cash';
    }
    
    /**
     * Generate egg sale notes
     */
    private function generateEggSaleNotes($faker, $quantity, $unitPrice)
    {
        $notes = [
            "Regular egg sale - {$quantity} eggs at \${$unitPrice} each",
            "Fresh farm eggs - customer pickup",
            "Quality eggs from free-range layers",
            "Premium grade eggs - special order",
            "Daily egg collection and sale",
        ];
        
        return $faker->randomElement($notes);
    }
} 