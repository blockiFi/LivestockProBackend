<?php

namespace Database\Factories;

use App\Models\Flock;
use App\Models\Farm;
use App\Models\PoultryHouse;
use App\Models\PoultryType;
use App\Models\FlockStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Flock>
 */
class FlockFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Flock::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'farm_id' => Farm::factory(),
            'house_id' => PoultryHouse::factory(),
            'poultry_weight_report_frequency_id' => null,
            'poultry_type_id' => PoultryType::factory(),
            'flock_stage_id' => FlockStage::factory(),
            'name' => $this->faker->word,
            'batch_number' => $this->faker->unique()->numerify('BATCH-####'),
            'breed' => $this->faker->word,
            'source' => $this->faker->company,
            'quantity' => $this->faker->numberBetween(100, 10000),
            'arrival_date' => $this->faker->date(),
            'arrival_age_days' => $this->faker->numberBetween(1, 30),
            'status' => $this->faker->randomElement(['active', 'sold', 'culled', 'completed']),
            'expected_end_date' => $this->faker->optional()->date(),
            'actual_end_date' => $this->faker->optional()->date(),
            'notes' => $this->faker->optional()->paragraph,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
} 