<?php

namespace Database\Factories;

use App\Models\PoultryHouse;
use App\Models\Farm;
use App\Models\PoultryType;
use Illuminate\Database\Eloquent\Factories\Factory;

class PoultryHouseFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = PoultryHouse::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->word,
            'capacity' => $this->faker->numberBetween(100, 10000),
            'status' => $this->faker->randomElement(['active', 'inactive', 'maintenance', 'empty']),
            'poultry_type_id' => PoultryType::factory(),
            'liter_type_id' => $this->faker->randomElement(['deepLiter', 'bateryCage']),
            'dimensions' => $this->faker->optional()->sentence,
            'construction_date' => $this->faker->optional()->date(),
            'last_maintenance_date' => $this->faker->optional()->date(),
            'notes' => $this->faker->optional()->paragraph,
            'farm_id' => Farm::factory(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
} 