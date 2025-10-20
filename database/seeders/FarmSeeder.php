<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Farm;
use App\Models\Country;
use App\Models\User;

class FarmSeeder extends Seeder
{
    public function run()
    {
        $countries = Country::all();
        $users = User::all();
        foreach (range(1, 5) as $i) {
            $farm = Farm::factory()->create([
                'country_id' => $countries->random()->id,
            ]);
            // Assign a random user as owner/manager
            $farm->users()->attach($users->random()->id);
        }
    }
} 