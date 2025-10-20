<?php

namespace Database\Seeders;

use App\Models\PoultryVaccine;
use App\Models\PoultryVaccineProduct;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PoultryVaccineProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Disable foreign key checks
        Schema::disableForeignKeyConstraints();

        // Truncate the table to avoid duplicates
        DB::table('poultry_vaccine_products')->truncate();

        // Re-enable foreign key checks
        Schema::enableForeignKeyConstraints();

        // Get all vaccines
        $vaccines = PoultryVaccine::all();

        // Common manufacturers
        $manufacturers = [
            'Merck Animal Health',
            'Zoetis',
            'Boehringer Ingelheim',
            'Elanco',
            'Ceva Animal Health',
            'Huvepharma',
            'Virbac',
            'Vaxxinova',
            'Lohmann Animal Health',
            'Merial'
        ];

        // Dosage units
        $dosageUnits = ['mL', 'drops', 'spray', 'injection'];

        // Withdrawal period units
        $withdrawalUnits = ['days', 'hours'];

        // Get administration methods from DB
        $adminMethods = DB::table('administration_methods')->pluck('id', 'name')->toArray();

        // Map vaccine names to administration methods
        $vaccineMethodMap = [
            'Marek' => 'Injection (Subcutaneous)',
            'Newcastle' => 'Eye Drop',
            'Infectious Bursal Disease' => 'Drinking Water',
            'Gumboro' => 'Drinking Water',
            'Infectious Bronchitis' => 'Spray',
            'Fowl Pox' => 'Wing-Web Stab',
            'Fowl Cholera' => 'Injection (Intramuscular)',
            'Avian Encephalomyelitis' => 'Wing-Web Stab',
            'Infectious Coryza' => 'Injection (Intramuscular)',
            'Mycoplasma gallisepticum' => 'Spray',
            'Mycoplasma synoviae' => 'Spray',
            'Avian Influenza' => 'Injection (Intramuscular)',
            'Salmonella' => 'Injection (Intramuscular)',
        ];

        foreach ($vaccines as $vaccine) {
            // Find the best matching method for this vaccine
            $methodId = null;
            foreach ($vaccineMethodMap as $key => $methodName) {
                if (stripos($vaccine->name, $key) !== false && isset($adminMethods[$methodName])) {
                    $methodId = $adminMethods[$methodName];
                    break;
                }
            }
            // Fallback to a random method if not mapped
            if (!$methodId) {
                $methodId = collect($adminMethods)->random();
            }

            // Create 10 products for each vaccine
            for ($i = 1; $i <= 10; $i++) {
                $manufacturer = $manufacturers[array_rand($manufacturers)];
                $dosageUnit = $dosageUnits[array_rand($dosageUnits)];
                $withdrawalUnit = $withdrawalUnits[array_rand($withdrawalUnits)];

                PoultryVaccineProduct::create([
                    'farm_id' => null, // Default products
                    'type' => 'default',
                    'poultry_vaccine_id' => $vaccine->id,
                    'name' => $manufacturer . ' ' . $vaccine->name . ' ' . $i,
                    'image_url' => null,
                    'manufacturer' => $manufacturer,
                    'administration_method_id' => $methodId,
                    'withdrawal_period' => rand(0, 30),
                    'withdrawal_period_unit' => $withdrawalUnit,
                    'dosage' => rand(1, 10),
                    'dosage_unit' => $dosageUnit,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->command->info('Poultry vaccine products seeded successfully!');
    }
}
