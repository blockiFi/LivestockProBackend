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
        // Disable foreign key checks for clean seeding
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('model_has_roles')->truncate();
        DB::table('role_has_permissions')->truncate();
        DB::table('roles')->whereNull('farm_id')->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->call([
            // 1. Foundation
            PermissionSeeder::class,
            CountrySeeder::class,
            UserSeeder::class,

            // 2. Farm Infrastructure
            FarmSeeder::class,
            PoultryHouseSeeder::class,

            // 3. Poultry Management
            PoultryTypeSeeder::class,
            FlockStageSeeder::class,

            // 4. Inventory Lookups
            PoultryFeedTypeSeeder::class,
            AdministrationMethodSeeder::class,
            PoultryVaccineSeeder::class,
            PoultryVaccineProductSeeder::class,
            PoultryMedicationSeeder::class,
            MedicationProductSeeder::class,

            // 5. Inventory
            EnhancedInventorySeeder::class,

            // 6. Flocks
            EnhancedFlockSeeder::class,

            // 7. Daily Operations
            EnhancedFlockDailyRecordSeeder::class,
            EnhancedFeedUsageSeeder::class,
            EnhancedVaccinationRecordSeeder::class,

            // 8. Business
            CustomerSeeder::class,
            EnhancedSalesRecordSeeder::class,

            // 9. Scheduling
            FeedingScheduleSeeder::class,
            FeedingScheduleItemSeeder::class,
            FeedingBatchScheduleSeeder::class,
            FeedingBatchScheduleItemSeeder::class,
            ScheduleSeeder::class,
            ScheduleItemSeeder::class,
            BatchScheduleSeeder::class,
            BatchScheduleItemSeeder::class,

            // 10. Legacy
            PoultryFeedUsageSeeder::class,
            PoultryMedicationRecordSeeder::class,
            PoultryVaccinationRecordSeeder::class,
            FlockDailyRecordSeeder::class,
            PoultryFlockEggReportSeeder::class,
            PoultryFlockWeightReportSeeder::class,
            PoultryMortalityReportSeeder::class,
            SalesRecordSeeder::class,

            // 11. Events
            PoultryEventSeeder::class,
        ]);
    }
}
