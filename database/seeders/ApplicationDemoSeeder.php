<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ApplicationDemoSeeder extends Seeder
{
    /**
     * Seed a rich, realistic demo dataset.
     *
     * WARNING: This seeder truncates core domain tables and is intended
     *          for local development / demo environments only.
     */
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        // Core permission / role tables (Spatie)
        $this->safeTruncate('model_has_permissions');
        $this->safeTruncate('model_has_roles');
        $this->safeTruncate('role_has_permissions');
        $this->safeTruncate('roles');
        $this->safeTruncate('permissions');

        // Farm & user relations
        $this->safeTruncate('subscription_transactions');
        $this->safeTruncate('subscription_waivers');
        $this->safeTruncate('farm_subscriptions');
        $this->safeTruncate('farm_users');
        $this->safeTruncate('farms');

        // Poultry master data
        $this->safeTruncate('poultry_types');
        $this->safeTruncate('flock_stages');
        $this->safeTruncate('poultry_houses');

        // Feed master data & inventory
        $this->safeTruncate('feed_components');
        $this->safeTruncate('poultry_feed_types');
        $this->safeTruncate('poultry_feed_products');
        $this->safeTruncate('feed_compositions');
        $this->safeTruncate('poultry_feed_inventories');

        // Medication / vaccine master data & inventory
        $this->safeTruncate('medication_products');
        $this->safeTruncate('poultry_medications');
        $this->safeTruncate('poultry_vaccines');
        $this->safeTruncate('poultry_vaccine_products');
        $this->safeTruncate('poultry_medication_inventories');
        $this->safeTruncate('poultry_vaccine_inventories');

        // Flocks & related records
        $this->safeTruncate('flocks');
        $this->safeTruncate('flock_daily_records');
        $this->safeTruncate('poultry_mortality_reports');
        $this->safeTruncate('poultry_flock_weight_reports');
        $this->safeTruncate('poultry_flock_egg_reports');
        $this->safeTruncate('flock_expenditures');
        $this->safeTruncate('poultry_feed_usages');
        $this->safeTruncate('poultry_medication_records');
        $this->safeTruncate('poultry_vaccination_records');

        // Scheduling (templates + batches)
        $this->safeTruncate('schedules');
        $this->safeTruncate('schedule_items');
        $this->safeTruncate('batch_schedules');
        $this->safeTruncate('batch_schedule_items');
        $this->safeTruncate('feeding_schedules');
        $this->safeTruncate('feeding_schedule_items');
        $this->safeTruncate('feeding_batch_schedules');
        $this->safeTruncate('feeding_batch_schedule_items');

        // Business / sales
        $this->safeTruncate('customers');
        $this->safeTruncate('sales_records');
        $this->safeTruncate('invoices');

        // Events & notifications
        $this->safeTruncate('poultry_events');

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        /**
         * Now call the existing enhanced seeders in a coherent order so that
         * all pages (dashboard, flocks, inventory, permissions, schedules)
         * have realistic demo data.
         */
        $this->call([
            // 1. Foundation
            PermissionSeeder::class,
            CountrySeeder::class,
            UserSeeder::class,

            // 2. Farm Infrastructure
            FarmSeeder::class,
            SubscriptionPlanSeeder::class,
            BackfillFarmSubscriptionsSeeder::class,
            PoultryHouseSeeder::class,

            // 3. Poultry Management
            PoultryTypeSeeder::class,
            FlockStageSeeder::class,

            // 4. Inventory Lookups
            FeedComponentSeeder::class,
            PoultryFeedTypeSeeder::class,
            PoultryFeedProductSeeder::class,
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

            // 9. Scheduling (medication, vaccination, feeding)
            EnhancedScheduleSeeder::class,
            EnhancedFeedingScheduleSeeder::class,
            BatchScheduleSeeder::class,
            BatchScheduleItemSeeder::class,

            // 10. Legacy / compatibility seeders (optional, but keep for now)
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

    /**
     * Safely truncate a table if it exists.
     */
    protected function safeTruncate(string $table): void
    {
        if (Schema::hasTable($table)) {
            DB::table($table)->truncate();
        }
    }
}

