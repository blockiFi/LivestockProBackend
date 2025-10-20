<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PoultryMedicationRecord;
use App\Models\Flock;
use App\Models\PoultryMedicationInventory;
use App\Models\MedicationProduct;

class PoultryMedicationRecordSeeder extends Seeder
{
    public function run()
    {
        $flocks = Flock::all();
        $inventories = PoultryMedicationInventory::all();
        $products = MedicationProduct::all();
        foreach ($flocks as $flock) {
            foreach (range(1, 2) as $i) {
                $inventory = $inventories->random();
                $product = $products->random();
                PoultryMedicationRecord::create([
                    'flock_id' => $flock->id,
                    'farm_id' => $flock->farm_id,
                    'poultry_medication_inventory_id' => $inventory->id,
                    'poultry_medication_id' => $product->poultry_medication_id,
                    'date' => now()->subDays($i),
                    'administered_by' => 'Vet ' . rand(1, 10),
                    'dosage' => 1,
                    'dosage_unit' => $product->dosage_unit ?? 'mL',
                    'quantity' => 10.0,
                    'cost' => 1000.0,
                    'notes' => 'Routine medication',
                    'administration_method_id' => $product->administration_method_id,
                ]);
            }
        }
    }
} 