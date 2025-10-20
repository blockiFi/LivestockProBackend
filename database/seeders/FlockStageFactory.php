<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PoultryType;

class FlockStageFactory extends Seeder
{
    public function run()
    {
        // ... existing code ...
        return [
            'name' => $this->faker->word . ' Stage',
            'poultry_type_id' => PoultryType::inRandomOrder()->first()?->id ?? 1,
            
        ];
        // ... existing code ...
    }
} 