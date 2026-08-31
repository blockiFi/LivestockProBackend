<?php

namespace Database\Seeders;

use App\Models\Farm;
use App\Models\FarmSubscription;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class BackfillFarmSubscriptionsSeeder extends Seeder
{
    /**
     * Give every pre-existing farm a trial so the new enforcement layer does
     * not lock anyone out the moment it ships.
     */
    public function run(): void
    {
        $this->call(SubscriptionPlanSeeder::class);

        $plan = SubscriptionPlan::where('slug', config('subscription.trial_plan', 'basic'))->firstOrFail();
        $trialDays = (int) config('subscription.trial_days', 14);

        $created = 0;

        Farm::whereDoesntHave('subscription')->chunkById(100, function ($farms) use ($plan, $trialDays, &$created) {
            foreach ($farms as $farm) {
                FarmSubscription::create([
                    'farm_id' => $farm->id,
                    'subscription_plan_id' => $plan->id,
                    'status' => FarmSubscription::STATUS_TRIALING,
                    'trial_ends_at' => now()->addDays($trialDays),
                ]);
                $created++;
            }
        });

        $this->command?->info("Created {$created} farm subscription(s) on the {$plan->name} trial.");
    }
}
