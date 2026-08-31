<?php

namespace Tests\Concerns;

use App\Models\Country;
use App\Models\Farm;
use App\Models\FarmSubscription;
use App\Models\Flock;
use App\Models\FlockStage;
use App\Models\PoultryHouse;
use App\Models\PoultryType;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\FarmEntitlementService;

trait InteractsWithSubscriptions
{
    protected function plan(string $slug): SubscriptionPlan
    {
        return SubscriptionPlan::where('slug', $slug)->firstOrFail();
    }

    /**
     * @return array{user: User, token: string, farm: Farm, country: Country}
     */
    protected function createSubscribedFarm(User $user, string $token): array
    {
        $country = Country::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/farms', [
            'name' => 'Subscription Test Farm',
            'address' => '12 Farm Road',
            'country_id' => $country->id,
            'state' => 'Lagos',
            'city' => 'Ikeja',
        ]);

        $response->assertCreated();

        $farm = Farm::findOrFail($response->json('data.id'));
        $this->defaultHeaders = [];

        return compact('user', 'token', 'farm', 'country');
    }

    protected function putFarmOnPlan(Farm $farm, string $slug, string $status = FarmSubscription::STATUS_ACTIVE): FarmSubscription
    {
        $subscription = app(FarmEntitlementService::class)->subscription($farm);
        $plan = $this->plan($slug);

        $subscription->fill([
            'subscription_plan_id' => $plan->id,
            'status' => $status,
            'trial_ends_at' => $status === FarmSubscription::STATUS_TRIALING
                ? now()->addDays(14)
                : now()->subDay(),
            'current_period_start' => $status === FarmSubscription::STATUS_ACTIVE ? now() : null,
            'current_period_end' => $status === FarmSubscription::STATUS_ACTIVE ? now()->addMonth() : null,
            'grace_ends_at' => $status === FarmSubscription::STATUS_GRACE ? now()->addDays(2) : null,
            'waived_until' => $status === FarmSubscription::STATUS_WAIVED ? now()->addMonths(3) : null,
        ])->save();

        return $subscription->fresh('plan');
    }

    protected function expireFarmSubscription(Farm $farm): FarmSubscription
    {
        $subscription = app(FarmEntitlementService::class)->subscription($farm);
        $subscription->fill([
            'status' => FarmSubscription::STATUS_READ_ONLY,
            'trial_ends_at' => now()->subDays(5),
            'current_period_end' => now()->subDays(5),
            'grace_ends_at' => now()->subDay(),
            'waived_until' => null,
        ])->save();

        return $subscription->fresh();
    }

    /**
     * @return array{house: PoultryHouse, type: PoultryType, stage: FlockStage}
     */
    protected function flockDependencies(Farm $farm): array
    {
        $type = PoultryType::factory()->create();
        $house = PoultryHouse::factory()->create([
            'farm_id' => $farm->id,
            'poultry_type_id' => $type->id,
            'capacity' => 10000,
            'status' => 'active',
        ]);
        $stage = FlockStage::factory()->create([
            'poultry_type_id' => $type->id,
            'from_age' => 0,
            'to_age' => 10000,
        ]);

        return ['house' => $house, 'type' => $type, 'stage' => $stage];
    }

    protected function createActiveFlock(Farm $farm, array $deps): Flock
    {
        return Flock::factory()->create([
            'farm_id' => $farm->id,
            'house_id' => $deps['house']->id,
            'poultry_type_id' => $deps['type']->id,
            'flock_stage_id' => $deps['stage']->id,
            'status' => 'active',
            'quantity' => 10,
            'poultry_weight_report_frequency_id' => null,
        ]);
    }
}
