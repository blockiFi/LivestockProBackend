<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\MedicationProduct;
use App\Models\PoultryMedication;

class MedicationProductSeeder extends Seeder
{
    public function run()
    {
        $medications = PoultryMedication::all();
        $methods = \App\Models\AdministrationMethod::pluck('id')->all();
        foreach ($medications as $medication) {
            MedicationProduct::create([
                'farm_id' => $medication->farm_id ?? 1,
                'type' => 'default',
                'poultry_medication_id' => $medication->id,
                'name' => $medication->name . ' Product',
                'image_url' => null,
                'manufacturer' => 'Generic Pharma',
                'administration_method_id' => $methods[array_rand($methods)],
                'withdrawal_period' => rand(0, 30),
                'withdrawal_period_unit' => 'days',
                'dosage' => 1,
                'dosage_unit' => 'mL',
            ]);
        }
    }
} 