<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'slug' => SubscriptionPlan::BASIC,
                'name' => 'Basic',
                'description' => 'One user and one active batch at a time.',
                'price_kobo' => 500000,
                'max_users' => 1,
                'max_active_flocks' => 1,
                'ai_enabled' => false,
                'sort_order' => 1,
            ],
            [
                'slug' => SubscriptionPlan::STANDARD,
                'name' => 'Standard',
                'description' => 'Unlimited users and unlimited active batches.',
                'price_kobo' => 1000000,
                'max_users' => null,
                'max_active_flocks' => null,
                'ai_enabled' => false,
                'sort_order' => 2,
            ],
            [
                'slug' => SubscriptionPlan::PREMIUM,
                'name' => 'Premium',
                'description' => 'Everything in Standard plus every AI feature.',
                'price_kobo' => 1500000,
                'max_users' => null,
                'max_active_flocks' => null,
                'ai_enabled' => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::updateOrCreate(
                ['slug' => $plan['slug']],
                $plan + ['currency' => 'NGN', 'is_active' => true]
            );
        }
    }
}
