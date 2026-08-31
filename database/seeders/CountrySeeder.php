<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class CountrySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = Carbon::now();
        $countries = [
            // Africa (all 54 UN member states)
            ['iso_code' => 'DZ', 'name' => 'Algeria', 'currency_code' => 'DZD', 'currency_name' => 'Algerian dinar', 'currency_symbol' => 'دج', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'AO', 'name' => 'Angola', 'currency_code' => 'AOA', 'currency_name' => 'Angolan kwanza', 'currency_symbol' => 'Kz', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'BJ', 'name' => 'Benin', 'currency_code' => 'XOF', 'currency_name' => 'West African CFA franc', 'currency_symbol' => 'CFA', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'BW', 'name' => 'Botswana', 'currency_code' => 'BWP', 'currency_name' => 'Botswana pula', 'currency_symbol' => 'P', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'BF', 'name' => 'Burkina Faso', 'currency_code' => 'XOF', 'currency_name' => 'West African CFA franc', 'currency_symbol' => 'CFA', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'BI', 'name' => 'Burundi', 'currency_code' => 'BIF', 'currency_name' => 'Burundian franc', 'currency_symbol' => 'FBu', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'CV', 'name' => 'Cabo Verde', 'currency_code' => 'CVE', 'currency_name' => 'Cape Verdean escudo', 'currency_symbol' => 'Esc', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'CM', 'name' => 'Cameroon', 'currency_code' => 'XAF', 'currency_name' => 'Central African CFA franc', 'currency_symbol' => 'FCFA', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'CF', 'name' => 'Central African Republic', 'currency_code' => 'XAF', 'currency_name' => 'Central African CFA franc', 'currency_symbol' => 'FCFA', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'TD', 'name' => 'Chad', 'currency_code' => 'XAF', 'currency_name' => 'Central African CFA franc', 'currency_symbol' => 'FCFA', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'KM', 'name' => 'Comoros', 'currency_code' => 'KMF', 'currency_name' => 'Comorian franc', 'currency_symbol' => 'CF', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'CG', 'name' => 'Congo', 'currency_code' => 'XAF', 'currency_name' => 'Central African CFA franc', 'currency_symbol' => 'FCFA', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'CD', 'name' => 'Congo (DRC)', 'currency_code' => 'CDF', 'currency_name' => 'Congolese franc', 'currency_symbol' => 'FC', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'CI', 'name' => 'Côte d\'Ivoire', 'currency_code' => 'XOF', 'currency_name' => 'West African CFA franc', 'currency_symbol' => 'CFA', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'DJ', 'name' => 'Djibouti', 'currency_code' => 'DJF', 'currency_name' => 'Djiboutian franc', 'currency_symbol' => 'Fdj', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'EG', 'name' => 'Egypt', 'currency_code' => 'EGP', 'currency_name' => 'Egyptian pound', 'currency_symbol' => '£', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'GQ', 'name' => 'Equatorial Guinea', 'currency_code' => 'XAF', 'currency_name' => 'Central African CFA franc', 'currency_symbol' => 'FCFA', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'ER', 'name' => 'Eritrea', 'currency_code' => 'ERN', 'currency_name' => 'Eritrean nakfa', 'currency_symbol' => 'Nfk', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'SZ', 'name' => 'Eswatini', 'currency_code' => 'SZL', 'currency_name' => 'Swazi lilangeni', 'currency_symbol' => 'E', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'ET', 'name' => 'Ethiopia', 'currency_code' => 'ETB', 'currency_name' => 'Ethiopian birr', 'currency_symbol' => 'Br', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'GA', 'name' => 'Gabon', 'currency_code' => 'XAF', 'currency_name' => 'Central African CFA franc', 'currency_symbol' => 'FCFA', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'GM', 'name' => 'Gambia', 'currency_code' => 'GMD', 'currency_name' => 'Gambian dalasi', 'currency_symbol' => 'D', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'GH', 'name' => 'Ghana', 'currency_code' => 'GHS', 'currency_name' => 'Ghanaian cedi', 'currency_symbol' => 'GH₵', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'GN', 'name' => 'Guinea', 'currency_code' => 'GNF', 'currency_name' => 'Guinean franc', 'currency_symbol' => 'FG', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'GW', 'name' => 'Guinea-Bissau', 'currency_code' => 'XOF', 'currency_name' => 'West African CFA franc', 'currency_symbol' => 'CFA', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'KE', 'name' => 'Kenya', 'currency_code' => 'KES', 'currency_name' => 'Kenyan shilling', 'currency_symbol' => 'KSh', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'LS', 'name' => 'Lesotho', 'currency_code' => 'LSL', 'currency_name' => 'Lesotho loti', 'currency_symbol' => 'L', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'LR', 'name' => 'Liberia', 'currency_code' => 'LRD', 'currency_name' => 'Liberian dollar', 'currency_symbol' => '$', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'LY', 'name' => 'Libya', 'currency_code' => 'LYD', 'currency_name' => 'Libyan dinar', 'currency_symbol' => 'ل.د', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'MG', 'name' => 'Madagascar', 'currency_code' => 'MGA', 'currency_name' => 'Malagasy ariary', 'currency_symbol' => 'Ar', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'MW', 'name' => 'Malawi', 'currency_code' => 'MWK', 'currency_name' => 'Malawian kwacha', 'currency_symbol' => 'MK', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'ML', 'name' => 'Mali', 'currency_code' => 'XOF', 'currency_name' => 'West African CFA franc', 'currency_symbol' => 'CFA', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'MR', 'name' => 'Mauritania', 'currency_code' => 'MRU', 'currency_name' => 'Mauritanian ouguiya', 'currency_symbol' => 'UM', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'MU', 'name' => 'Mauritius', 'currency_code' => 'MUR', 'currency_name' => 'Mauritian rupee', 'currency_symbol' => '₨', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'MA', 'name' => 'Morocco', 'currency_code' => 'MAD', 'currency_name' => 'Moroccan dirham', 'currency_symbol' => 'د.م.', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'MZ', 'name' => 'Mozambique', 'currency_code' => 'MZN', 'currency_name' => 'Mozambican metical', 'currency_symbol' => 'MT', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'NA', 'name' => 'Namibia', 'currency_code' => 'NAD', 'currency_name' => 'Namibian dollar', 'currency_symbol' => '$', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'NE', 'name' => 'Niger', 'currency_code' => 'XOF', 'currency_name' => 'West African CFA franc', 'currency_symbol' => 'CFA', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'NG', 'name' => 'Nigeria', 'currency_code' => 'NGN', 'currency_name' => 'Nigerian naira', 'currency_symbol' => '₦', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'RW', 'name' => 'Rwanda', 'currency_code' => 'RWF', 'currency_name' => 'Rwandan franc', 'currency_symbol' => 'FRw', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'ST', 'name' => 'São Tomé and Príncipe', 'currency_code' => 'STN', 'currency_name' => 'São Tomé and Príncipe dobra', 'currency_symbol' => 'Db', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'SN', 'name' => 'Senegal', 'currency_code' => 'XOF', 'currency_name' => 'West African CFA franc', 'currency_symbol' => 'CFA', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'SC', 'name' => 'Seychelles', 'currency_code' => 'SCR', 'currency_name' => 'Seychellois rupee', 'currency_symbol' => '₨', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'SL', 'name' => 'Sierra Leone', 'currency_code' => 'SLE', 'currency_name' => 'Sierra Leonean leone', 'currency_symbol' => 'Le', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'SO', 'name' => 'Somalia', 'currency_code' => 'SOS', 'currency_name' => 'Somali shilling', 'currency_symbol' => 'Sh', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'ZA', 'name' => 'South Africa', 'currency_code' => 'ZAR', 'currency_name' => 'South African rand', 'currency_symbol' => 'R', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'SS', 'name' => 'South Sudan', 'currency_code' => 'SSP', 'currency_name' => 'South Sudanese pound', 'currency_symbol' => '£', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'SD', 'name' => 'Sudan', 'currency_code' => 'SDG', 'currency_name' => 'Sudanese pound', 'currency_symbol' => 'ج.س.', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'TZ', 'name' => 'Tanzania', 'currency_code' => 'TZS', 'currency_name' => 'Tanzanian shilling', 'currency_symbol' => 'TSh', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'TG', 'name' => 'Togo', 'currency_code' => 'XOF', 'currency_name' => 'West African CFA franc', 'currency_symbol' => 'CFA', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'TN', 'name' => 'Tunisia', 'currency_code' => 'TND', 'currency_name' => 'Tunisian dinar', 'currency_symbol' => 'د.ت', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'UG', 'name' => 'Uganda', 'currency_code' => 'UGX', 'currency_name' => 'Ugandan shilling', 'currency_symbol' => 'USh', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'ZM', 'name' => 'Zambia', 'currency_code' => 'ZMW', 'currency_name' => 'Zambian kwacha', 'currency_symbol' => 'K', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['iso_code' => 'ZW', 'name' => 'Zimbabwe', 'currency_code' => 'ZWL', 'currency_name' => 'Zimbabwean dollar', 'currency_symbol' => '$', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],

            // NOTE: You asked for "all African countries". If you also want territories (e.g., Western Sahara), tell me and I’ll add them.
        ];
        // Cross-database safe wipe (works on MySQL + SQLite)
        Schema::disableForeignKeyConstraints();
        DB::table('countries')->delete();
        Schema::enableForeignKeyConstraints();
        foreach ($countries as $country) {
            DB::table('countries')->updateOrInsert(
                ['iso_code' => $country['iso_code']],
                $country
            );
        }
    }
} 