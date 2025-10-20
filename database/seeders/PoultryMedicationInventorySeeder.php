<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PoultryMedicationInventory;
use App\Models\Farm;
use App\Models\MedicationProduct;

class PoultryMedicationInventorySeeder extends Seeder
{
    public function run()
    {
        $farms = Farm::all();
        $products = MedicationProduct::all();
        foreach ($farms as $farm) {
            foreach ($products as $product) {
                PoultryMedicationInventory::create([
                    'medication_product_id' => $product->id,
                    'farm_id' => $farm->id,
                    'quantity' => 100.0,
                    'batch_number' => 'BN' . rand(1000, 9999),
                    'status' => 'available',
                    'manufacture_date' => now()->subMonths(rand(1, 12)),
                    'expiry_date' => now()->addMonths(rand(1, 12)),
                    'unit_cost' => 1500.0,
                ]);
            }
        }
    }
} 