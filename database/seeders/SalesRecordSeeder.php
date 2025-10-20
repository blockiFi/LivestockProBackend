<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SalesRecord;
use App\Models\Customer;
use App\Models\Farm;

class SalesRecordSeeder extends Seeder
{
    public function run()
    {
        $customers = Customer::all();
        $flockIds = \App\Models\Flock::pluck('id')->all();
        $userIds = \App\Models\User::pluck('id')->all();
        foreach ($customers as $customer) {
            foreach (range(1, 2) as $i) {
                SalesRecord::create([
                    'customer_id' => $customer->id,
                    'farm_id' => $customer->farm_id,
                    'flock_id' => count($flockIds) ? $flockIds[array_rand($flockIds)] : 1,
                    'quantity' => rand(10, 100),
                    'price_per_unit' => rand(100, 500),
                    'total_price' => rand(1000, 5000),
                    'date' => now()->subDays($i),
                    'sold_by' => count($userIds) ? $userIds[array_rand($userIds)] : 1,
                    'recorded_by' => count($userIds) ? $userIds[array_rand($userIds)] : 1,
                    'notes' => 'Sales record note',
                    'type' => ['egg', 'meat', 'manure'][array_rand(['egg', 'meat', 'manure'])],
                ]);
            }
        }
    }
} 