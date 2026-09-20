<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Flock;
use App\Models\SalesRecord;
use App\Models\User;
use Illuminate\Database\Seeder;

class SalesRecordSeeder extends Seeder
{
    public function run(): void
    {
        $customers = Customer::all();
        $flockIds = Flock::pluck('id')->all();
        $userIds = User::pluck('id')->all();

        foreach ($customers as $customer) {
            foreach (range(1, 2) as $i) {
                $type = ['egg', 'meat', 'manure'][array_rand(['egg', 'meat', 'manure'])];
                // Egg sales store crates + price per crate; other types keep product units.
                $quantity = $type === 'egg' ? rand(1, 10) : rand(10, 100);
                $unitPrice = $type === 'egg' ? rand(1200, 2000) : rand(100, 500);

                SalesRecord::create([
                    'customer_id' => $customer->id,
                    'farm_id' => $customer->farm_id,
                    'flock_id' => count($flockIds) ? $flockIds[array_rand($flockIds)] : null,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_amount' => $quantity * $unitPrice,
                    'date' => now()->subDays($i)->toDateString(),
                    'payment_status' => 'paid',
                    'notes' => 'Sales record note',
                    'type' => $type,
                    'created_by' => count($userIds) ? $userIds[array_rand($userIds)] : null,
                ]);
            }
        }
    }
}
