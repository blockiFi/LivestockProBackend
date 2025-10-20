<?php

namespace Database\Seeders;

use App\Models\PoultryVaccine;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PoultryVaccineSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Disable foreign key checks
        Schema::disableForeignKeyConstraints();

        // Truncate the table to avoid duplicates
        DB::table('poultry_vaccines')->truncate();

        // Re-enable foreign key checks
        Schema::enableForeignKeyConstraints();

        $vaccines = [
            ['name' => 'Newcastle Disease Vaccine', 'type' => 'default', 'description' => 'Protects against Newcastle disease'],
            ['name' => 'Marek\'s Disease Vaccine', 'type' => 'default', 'description' => 'Protects against Marek\'s disease'],
            ['name' => 'Fowl Pox Vaccine', 'type' => 'default', 'description' => 'Protects against Fowl Pox'],
            ['name' => 'Infectious Bronchitis Vaccine', 'type' => 'default', 'description' => 'Protects against Infectious Bronchitis'],
        ];

        // Insert the vaccines
        foreach ($vaccines as $vaccine) {
            PoultryVaccine::firstOrCreate(['name' => $vaccine['name']], $vaccine);
        }
    }
}
