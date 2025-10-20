<?php

namespace Database\Factories;

use App\Models\FlockStage;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\PoultryType;

class FlockStageFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = FlockStage::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word,
            'description' => $this->faker->sentence,
            'poultry_type_id' => PoultryType::inRandomOrder()->first()?->id ?? 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
} 