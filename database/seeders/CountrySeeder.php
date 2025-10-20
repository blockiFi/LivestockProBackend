<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class CountrySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = Carbon::now();
        $countries = [
            // Africa
            [
                'iso_code' => 'DZ', 'name' => 'Algeria', 'currency_code' => 'DZD', 'currency_name' => 'Algerian dinar', 'currency_symbol' => 'دج', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now
            ],
            [
                'iso_code' => 'AO', 'name' => 'Angola', 'currency_code' => 'AOA', 'currency_name' => 'Kwanza', 'currency_symbol' => 'Kz', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now
            ],
            [
                'iso_code' => 'BJ', 'name' => 'Benin', 'currency_code' => 'XOF', 'currency_name' => 'West African CFA franc', 'currency_symbol' => 'CFA', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now
            ],
            [
                'iso_code' => 'BW', 'name' => 'Botswana', 'currency_code' => 'BWP', 'currency_name' => 'Pula', 'currency_symbol' => 'P', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now
            ],
            [
                'iso_code' => 'BF', 'name' => 'Burkina Faso', 'currency_code' => 'XOF', 'currency_name' => 'West African CFA franc', 'currency_symbol' => 'CFA', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now
            ],
            [
                'iso_code' => 'BI', 'name' => 'Burundi', 'currency_code' => 'BIF', 'currency_name' => 'Burundian franc', 'currency_symbol' => 'FBu', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now
            ],
            [
                'iso_code' => 'CM', 'name' => 'Cameroon', 'currency_code' => 'XAF', 'currency_name' => 'Central African CFA franc', 'currency_symbol' => 'FCFA', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now
            ],
            [
                'iso_code' => 'CV', 'name' => 'Cabo Verde', 'currency_code' => 'CVE', 'currency_name' => 'Cape Verdean escudo', 'currency_symbol' => '$', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now
            ],
            [
                'iso_code' => 'CF', 'name' => 'Central African Republic', 'currency_code' => 'XAF', 'currency_name' => 'Central African CFA franc', 'currency_symbol' => 'FCFA', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now
            ],
            [
                'iso_code' => 'TD', 'name' => 'Chad', 'currency_code' => 'XAF', 'currency_name' => 'Central African CFA franc', 'currency_symbol' => 'FCFA', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now
            ],
            [
                'iso_code' => 'KM', 'name' => 'Comoros', 'currency_code' => 'KMF', 'currency_name' => 'Comorian franc', 'currency_symbol' => 'CF', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now
            ],
            [
                'iso_code' => 'CG', 'name' => 'Congo', 'currency_code' => 'XAF', 'currency_name' => 'Central African CFA franc', 'currency_symbol' => 'FCFA', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now
            ],
            [
                'iso_code' => 'CD', 'name' => 'Congo (DRC)', 'currency_code' => 'CDF', 'currency_name' => 'Congolese franc', 'currency_symbol' => 'FC', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now
            ],
            [
                'iso_code' => 'CI', 'name' => 'Côte d\'Ivoire', 'currency_code' => 'XOF', 'currency_name' => 'West African CFA franc', 'currency_symbol' => 'CFA', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now
            ],
            [
                'iso_code' => 'DJ', 'name' => 'Djibouti', 'currency_code' => 'DJF', 'currency_name' => 'Djiboutian franc', 'currency_symbol' => 'Fdj', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now
            ],
            [
                'iso_code' => 'EG', 'name' => 'Egypt', 'currency_code' => 'EGP', 'currency_name' => 'Egyptian pound', 'currency_symbol' => '£', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now
            ],
            [
                'iso_code' => 'GQ', 'name' => 'Equatorial Guinea', 'currency_code' => 'XAF', 'currency_name' => 'Central African CFA franc', 'currency_symbol' => 'FCFA', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now
            ],
            [
                'iso_code' => 'ER', 'name' => 'Eritrea', 'currency_code' => 'ERN', 'currency_name' => 'Eritrean nakfa', 'currency_symbol' => 'Nfk', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now
            ],
            [
                'iso_code' => 'SZ', 'name' => 'Eswatini', 'currency_code' => 'SZL', 'currency_name' => 'Swazi lilangeni', 'currency_symbol' => 'E', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now
            ],
            [
                'iso_code' => 'ET', 'name' => 'Ethiopia', 'currency_code' => 'ETB', 'currency_name' => 'Ethiopian birr', 'currency_symbol' => 'Br', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now
            ],
            // ... (continue for all other continents/countries in next chunks)
        ];
        // Safe deletion for foreign key constraints
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('countries')->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        foreach ($countries as $country) {
            DB::table('countries')->updateOrInsert(
                ['iso_code' => $country['iso_code']],
                $country
            );
        }
    }
} 