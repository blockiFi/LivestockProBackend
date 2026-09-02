<?php

namespace Database\Seeders;

use Database\Seeders\LayerDemo\LayerDemoFlockOperationsSeeder;
use Database\Seeders\LayerDemo\LayerDemoInventorySeeder;
use Database\Seeders\LayerDemo\LayerDemoScheduleSeeder;
use Database\Seeders\LayerDemo\LayerDemoUserSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LayerDemoFarmSeeder extends Seeder
{
    /**
     * Seed a single fully functional layer farm with one 400-day-old flock.
     *
     * WARNING: Truncates core domain tables. For local development / demo only.
     *
     * Run: php artisan db:seed --class=LayerDemoFarmSeeder
     */
    public function run(): void
    {
        $this->disableForeignKeys();

        $this->safeTruncate('model_has_permissions');
        $this->safeTruncate('model_has_roles');
        $this->safeTruncate('role_has_permissions');
        $this->safeTruncate('roles');
        $this->safeTruncate('permissions');

        $this->safeTruncate('subscription_transactions');
        $this->safeTruncate('subscription_waivers');
        $this->safeTruncate('farm_subscriptions');
        $this->safeTruncate('farm_users');
        $this->safeTruncate('farms');
        $this->safeTruncate('users');

        $this->safeTruncate('poultry_types');
        $this->safeTruncate('flock_stages');
        $this->safeTruncate('poultry_houses');

        $this->safeTruncate('feed_components');
        $this->safeTruncate('poultry_feed_types');
        $this->safeTruncate('poultry_feed_products');
        $this->safeTruncate('feed_compositions');
        $this->safeTruncate('poultry_feed_inventories');

        $this->safeTruncate('medication_products');
        $this->safeTruncate('poultry_medications');
        $this->safeTruncate('poultry_vaccines');
        $this->safeTruncate('poultry_vaccine_products');
        $this->safeTruncate('poultry_medication_inventories');
        $this->safeTruncate('poultry_vaccine_inventories');

        $this->safeTruncate('flocks');
        $this->safeTruncate('flock_daily_records');
        $this->safeTruncate('poultry_mortality_reports');
        $this->safeTruncate('poultry_flock_weight_reports');
        $this->safeTruncate('poultry_flock_egg_reports');
        $this->safeTruncate('flock_expenditures');
        $this->safeTruncate('poultry_feed_usages');
        $this->safeTruncate('poultry_medication_records');
        $this->safeTruncate('poultry_vaccination_records');

        $this->safeTruncate('schedules');
        $this->safeTruncate('schedule_items');
        $this->safeTruncate('batch_schedules');
        $this->safeTruncate('batch_schedule_items');
        $this->safeTruncate('feeding_schedules');
        $this->safeTruncate('feeding_schedule_items');
        $this->safeTruncate('feeding_batch_schedules');
        $this->safeTruncate('feeding_batch_schedule_items');

        $this->safeTruncate('customers');
        $this->safeTruncate('sales_records');
        $this->safeTruncate('invoices');
        $this->safeTruncate('flock_sales');

        $this->safeTruncate('poultry_events');

        $this->enableForeignKeys();

        $this->call([
            PermissionSeeder::class,
            CountrySeeder::class,
            LayerDemoUserSeeder::class,
            SubscriptionPlanSeeder::class,
            BackfillFarmSubscriptionsSeeder::class,
            PoultryTypeSeeder::class,
            FlockStageSeeder::class,
            FeedComponentSeeder::class,
            PoultryFeedTypeSeeder::class,
            PoultryFeedProductSeeder::class,
            AdministrationMethodSeeder::class,
            PoultryVaccineSeeder::class,
            PoultryVaccineProductSeeder::class,
            PoultryMedicationSeeder::class,
            MedicationProductSeeder::class,
            LayerDemoInventorySeeder::class,
            LayerDemoScheduleSeeder::class,
            LayerDemoFlockOperationsSeeder::class,
        ]);

        $this->command?->info('Layer demo farm seeded. Login: owner1@poultry.com / password');
    }

    protected function safeTruncate(string $table): void
    {
        if (Schema::hasTable($table)) {
            DB::table($table)->truncate();
        }
    }

    protected function disableForeignKeys(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }
    }

    protected function enableForeignKeys(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}
