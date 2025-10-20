<?php

namespace Database\Factories;

use App\Models\Farm;
use App\Models\User;
use App\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

class FarmFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Farm::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company,
            'address' => $this->faker->address,
            'phone' => $this->faker->phoneNumber,
            'email' => $this->faker->companyEmail,
            'country_id' => 1,
            'state' => $this->faker->state,
            'city' => $this->faker->city,
            'postal_code' => $this->faker->postcode,
            'website' => $this->faker->url,
            'logo' => null,
            'established_date' => $this->faker->date(),
            'size_hectares' => $this->faker->randomFloat(2, 1, 1000),
            'registration_number' => $this->faker->unique()->numerify('FARM-####'),
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
} 