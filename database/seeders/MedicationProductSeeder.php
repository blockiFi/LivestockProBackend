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
        if (empty($methods)) {
            return;
        }

        foreach ($medications as $medication) {
            MedicationProduct::create([
                'farm_id' => null, // platform default — visible to all farms
                'type' => 'default',
                'poultry_medication_id' => $medication->id,
                'name' => $medication->name . ' Product',
                'image_url' => null,
                'manufacturer' => 'Generic Pharma',
                'administration_method_id' => $methods[array_rand($methods)],
                'withdrawal_period' => rand(0, 30),
                'withdrawal_period_unit' => 'days',
                // Label mix ratios
                'preventive_medicine_amount' => 1,
                'preventive_medicine_unit' => 'g',
                'preventive_diluent_amount' => 1,
                'preventive_diluent_unit' => 'L',
                'preventive_diluent_type' => 'water',
                'treatment_medicine_amount' => 2,
                'treatment_medicine_unit' => 'g',
                'treatment_diluent_amount' => 1,
                'treatment_diluent_unit' => 'L',
                'treatment_diluent_type' => 'water',
                // Legacy sync from treatment
                'dosage' => 2,
                'dosage_unit' => 'g',
            ]);
        }
    }
}
