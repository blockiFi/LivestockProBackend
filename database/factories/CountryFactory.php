<?php

namespace Database\Factories;

use App\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

class CountryFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Country::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $currencyCode = $this->faker->currencyCode;
        return [
            'name' => $this->faker->unique()->country,
            'iso_code' => $this->faker->unique()->countryCode,
            'currency_code' => $this->faker->unique()->currencyCode,
            'currency_name' => $currencyCode, // Using currency code as name since Faker doesn't have currency names
            'currency_symbol' => $this->faker->randomElement(['$', '€', '£', '¥', '₹', '₣', '₩', '₴', '₦', '₱']),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
} 