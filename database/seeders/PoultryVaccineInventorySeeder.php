<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PoultryVaccineInventory;
use App\Models\Farm;
use App\Models\PoultryVaccineProduct;

class PoultryVaccineInventorySeeder extends Seeder
{
    public function run()
    {
        $farms = Farm::all();
        $products = PoultryVaccineProduct::all();
        foreach ($farms as $farm) {
            foreach ($products as $product) {
                PoultryVaccineInventory::create([
                    'farm_id' => $farm->id,
                    'poultry_vaccine_product_id' => $product->id,
                    'quantity' => 100.0,
                    'status' => 'available',
                    'batch_number' => 'BN' . rand(1000, 9999),
                    'manufacture_date' => now()->subMonths(rand(1, 12)),
                    'expiry_date' => now()->addMonths(rand(1, 12)),
                    'unit_cost' => 2000.0,
                ]);
            }
        }
    }
} 