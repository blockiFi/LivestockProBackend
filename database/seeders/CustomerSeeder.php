<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Customer;
use App\Models\Farm;
use App\Models\Country;

class CustomerSeeder extends Seeder
{
    public function run()
    {
        $farms = Farm::all();
        $country = Country::inRandomOrder()->first();
        if (!$country) {
            // No countries available, skip seeding customers
            return;
        }
        foreach ($farms as $farm) {
            foreach (range(1, 3) as $i) {
                Customer::create([
                    'farm_id' => $farm->id,
                    'name' => 'Customer ' . $farm->id . '-' . $i,
                    'email' => 'customer' . $farm->id . $i . '@example.com',
                    'phone' => '080' . rand(10000000, 99999999),
                    'address' => 'Address ' . $i,
                    'city' => 'City ' . $i,
                    'state' => 'State ' . $i,
                    'country_id' => $country->id,
                ]);
            }
        }
    }
} 