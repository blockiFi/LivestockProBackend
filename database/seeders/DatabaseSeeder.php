<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     * 
     * Seeder order is explicit and dependency-respecting:
     * 1. Foundation Setup
     * 2. Farm Infrastructure
     * 3. Poultry Management
     * 4. Inventory Lookups
     * 5. Inventory
     * 6. Flocks
     * 7. Daily Operations
     * 8. Business
     * 9. Scheduling
     * 10. Legacy
     * 11. Events
     */
    public function run(): void
    {
        /**
         * For rich demo data, delegate to ApplicationDemoSeeder,
         * which wipes and reseeds core tables in a consistent way.
         *
         * NOTE: Use this only in development / demo environments.
         */
        $this->call([
            ApplicationDemoSeeder::class,
        ]);
    }
}
