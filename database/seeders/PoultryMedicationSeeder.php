<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PoultryMedication;

class PoultryMedicationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $medications = [
            ['name' => 'Amprolium', 'type' => 'default', 'description' => 'Prevents and treats coccidiosis'],
            ['name' => 'Tylosin', 'type' => 'default', 'description' => 'Treats respiratory infections'],
            ['name' => 'Enrofloxacin', 'type' => 'default', 'description' => 'Broad-spectrum antibiotic'],
            ['name' => 'Vitamin AD3E', 'type' => 'default', 'description' => 'Supports growth and immunity'],
        ];
        foreach ($medications as $med) {
            PoultryMedication::firstOrCreate(['name' => $med['name']], $med);
        }
    }
} 