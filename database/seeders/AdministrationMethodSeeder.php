<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\AdministrationMethod;

class AdministrationMethodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Schema::disableForeignKeyConstraints();
        DB::table('administration_methods')->truncate();
        Schema::enableForeignKeyConstraints();

        $methods = [
            ['name' => 'Oral', 'description' => 'Given by mouth'],
            ['name' => 'Injection', 'description' => 'Given by injection'],
            ['name' => 'Spray', 'description' => 'Given by spray'],
            ['name' => 'Eye Drop', 'description' => 'Given as eye drop'],
            ['name' => 'Wing-Web', 'description' => 'Given by wing-web stab'],
            ['name' => 'Injection (Subcutaneous)', 'description' => 'Given by subcutaneous injection'],
            ['name' => 'Drinking Water', 'description' => 'Given in drinking water'],
            ['name' => 'Wing-Web Stab', 'description' => 'Given by wing-web stab'],
            ['name' => 'Injection (Intramuscular)', 'description' => 'Given by intramuscular injection'],
        ];
        foreach ($methods as $method) {
            AdministrationMethod::firstOrCreate(['name' => $method['name']], $method);
        }
    }
}
