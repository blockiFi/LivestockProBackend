<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PoultryVaccinationRecord;
use App\Models\Flock;
use App\Models\PoultryVaccineInventory;
use App\Models\PoultryVaccineProduct;

class PoultryVaccinationRecordSeeder extends Seeder
{
    public function run()
    {
        $flocks = Flock::all();
        $inventories = PoultryVaccineInventory::all();
        $products = PoultryVaccineProduct::all();
        foreach ($flocks as $flock) {
            foreach (range(1, 2) as $i) {
                $inventory = $inventories->random();
                $product = $products->random();
                PoultryVaccinationRecord::create([
                    'flock_id' => $flock->id,
                    'farm_id' => $flock->farm_id,
                    'poultry_vaccine_inventory_id' => $inventory->id,
                    'poultry_vaccine_id' => $product->poultry_vaccine_id,
                    'date' => now()->subDays($i),
                    'administered_by' => 'Vet ' . rand(1, 10),
                    'dosage' => 1,
                    'dosage_unit' => $product->dosage_unit ?? 'mL',
                    'quantity' => 10.0,
                    'cost' => 1000.0,
                    'notes' => 'Routine vaccination',
                    'administration_method_id' => $product->administration_method_id,
                ]);
            }
        }
    }
} 