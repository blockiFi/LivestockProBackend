<?php

namespace Database\Seeders\LayerDemo;

use App\Models\Farm;
use App\Models\MedicationProduct;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryFeedType;
use App\Models\PoultryMedicationInventory;
use App\Models\PoultryType;
use App\Models\PoultryVaccineInventory;
use App\Models\PoultryVaccineProduct;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class LayerDemoInventorySeeder extends Seeder
{
    public function run(): void
    {
        $farm = Farm::where('name', LayerDemoContext::FARM_NAME)->firstOrFail();
        $layerType = PoultryType::where('name', 'Layer')->firstOrFail();

        $this->seedLayerFeedTypes($layerType->id);
        $feedTypes = PoultryFeedType::where('poultry_type_id', $layerType->id)->get();

        foreach ($feedTypes as $feedType) {
            PoultryFeedInventory::create([
                'farm_id' => $farm->id,
                'poultry_feed_type_id' => $feedType->id,
                'batch_number' => 'LF-' . strtoupper(substr($feedType->name, 0, 3)) . '-001',
                'quantity' => 50000,
                'unit_cost' => match ($feedType->name) {
                    'Starter' => 3.20,
                    'Grower' => 2.80,
                    'Layer Mash' => 2.50,
                    default => 2.75,
                },
                'manufacturer' => 'Premier Feeds Ltd',
                'expiry_date' => Carbon::today()->addMonths(6),
            ]);
        }

        foreach (PoultryVaccineProduct::all() as $product) {
            PoultryVaccineInventory::create([
                'farm_id' => $farm->id,
                'poultry_vaccine_product_id' => $product->id,
                'batch_number' => 'VAC-' . $product->id . '-001',
                'quantity' => 5000,
                'available_quantity' => 5000,
                'unit_cost' => 0.15,
                'expiry_date' => Carbon::today()->addYear(),
            ]);
        }

        foreach (MedicationProduct::all() as $product) {
            PoultryMedicationInventory::create([
                'farm_id' => $farm->id,
                'medication_product_id' => $product->id,
                'batch_number' => 'MED-' . $product->id . '-001',
                'quantity' => 2000,
                'available_quantity' => 2000,
                'unit_cost' => 0.25,
                'manufacturer' => 'AgroMed Supplies',
                'expiry_date' => Carbon::today()->addMonths(18),
            ]);
        }
    }

    private function seedLayerFeedTypes(int $layerTypeId): void
    {
        $types = [
            ['name' => 'Starter', 'start_age' => 1, 'end_age' => 42, 'description' => 'Layer chick starter feed'],
            ['name' => 'Grower', 'start_age' => 43, 'end_age' => 112, 'description' => 'Layer grower feed'],
            ['name' => 'Layer Mash', 'start_age' => 113, 'end_age' => null, 'description' => 'Layer production mash'],
        ];

        foreach ($types as $type) {
            PoultryFeedType::firstOrCreate(
                ['name' => $type['name'], 'poultry_type_id' => $layerTypeId],
                [
                    'description' => $type['description'],
                    'type' => 'default',
                    'start_age' => $type['start_age'],
                    'end_age' => $type['end_age'],
                ]
            );
        }
    }
}
