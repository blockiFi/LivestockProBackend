<?php

namespace Database\Seeders;

use App\Models\EquipmentCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class EquipmentCategorySeeder extends Seeder
{
    /**
     * Default global equipment categories (farm_id = null).
     */
    public function run(): void
    {
        $categories = [
            'Poultry Equipment',
            'Livestock Equipment',
            'Feeding Equipment',
            'Watering Equipment',
            'Farm Machinery',
            'Tractors',
            'Generators',
            'Electrical Equipment',
            'Irrigation Equipment',
            'Processing Equipment',
            'Cold Storage Equipment',
            'Vehicles',
            'Tools',
            'Office Equipment',
            'Other',
        ];

        foreach ($categories as $index => $name) {
            EquipmentCategory::firstOrCreate(
                ['farm_id' => null, 'slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'is_active' => true,
                    'sort_order' => $index,
                ]
            );
        }
    }
}
