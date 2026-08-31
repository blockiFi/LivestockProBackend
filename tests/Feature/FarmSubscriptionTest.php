<?php

namespace Tests\Feature;

use App\Models\FarmSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSubscriptions;
use Tests\TestCase;

class FarmSubscriptionTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithSubscriptions;

    public function test_creating_a_farm_starts_a_basic_trial(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $ctx = $this->createSubscribedFarm($user, $token);
        $farm = $ctx['farm'];

        $this->assertDatabaseHas('farm_subscriptions', [
            'farm_id' => $farm->id,
            'status' => FarmSubscription::STATUS_TRIALING,
            'subscription_plan_id' => $this->plan(SubscriptionPlan::BASIC)->id,
        ]);

        $response = $this->withToken($token)->getJson("/api/farms/{$farm->id}/subscription");

        $response->assertOk()
            ->assertJsonPath('data.plan.slug', 'basic')
            ->assertJsonPath('data.status', 'trialing')
            ->assertJsonPath('data.ai_enabled', false)
            ->assertJsonPath('data.usage.max_users', 1)
            ->assertJsonPath('data.usage.max_active_flocks', 1);
    }

    public function test_plan_catalog_lists_three_naira_tiers(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/subscription/plans');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.slug', 'basic')
            ->assertJsonPath('data.0.price', 5000)
            ->assertJsonPath('data.1.slug', 'standard')
            ->assertJsonPath('data.1.price', 10000)
            ->assertJsonPath('data.2.slug', 'premium')
            ->assertJsonPath('data.2.price', 15000)
            ->assertJsonPath('data.2.ai_enabled', true);
    }

    public function test_checkout_without_paystack_returns_service_unavailable(): void
    {
        config(['paystack.secret_key' => null]);

        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $farm = $this->createSubscribedFarm($user, $token)['farm'];

        $response = $this->withToken($token)->postJson("/api/farms/{$farm->id}/subscription/checkout", [
            'plan_slug' => 'standard',
        ]);

        $response->assertStatus(503);
    }

    public function test_downgrade_is_blocked_when_farm_exceeds_basic_limits(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $farm = $this->createSubscribedFarm($user, $token)['farm'];
        $this->putFarmOnPlan($farm, SubscriptionPlan::STANDARD);

        $other = User::factory()->create();
        $farm->users()->attach($other->id);

        $response = $this->withToken($token)->postJson("/api/farms/{$farm->id}/subscription/change-plan", [
            'plan_slug' => 'basic',
        ]);

        $response->assertStatus(422);
    }
}
