<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSubscriptions;
use Tests\TestCase;

class FarmEntitlementTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithSubscriptions;

    public function test_basic_plan_blocks_a_second_user(): void
    {
        $owner = User::factory()->create();
        $token = $owner->createToken('test')->plainTextToken;
        $farm = $this->createSubscribedFarm($owner, $token)['farm'];
        $this->putFarmOnPlan($farm, SubscriptionPlan::BASIC);

        $other = User::factory()->create();

        $response = $this->withToken($token)->postJson("/api/farms/{$farm->id}/users", [
            'email' => $other->email,
            'role' => 'worker',
        ]);

        $response->assertStatus(402)
            ->assertJsonPath('code', 'plan_user_limit_reached');
        $this->assertFalse($farm->users()->where('users.id', $other->id)->exists());
    }

    public function test_basic_plan_blocks_a_pending_invite_when_at_cap(): void
    {
        $owner = User::factory()->create();
        $token = $owner->createToken('test')->plainTextToken;
        $farm = $this->createSubscribedFarm($owner, $token)['farm'];
        $this->putFarmOnPlan($farm, SubscriptionPlan::BASIC);

        $response = $this->withToken($token)->postJson("/api/farms/{$farm->id}/users/invite", [
            'email' => 'new-worker@example.com',
            'role' => 'worker',
        ]);

        $response->assertStatus(402)
            ->assertJsonPath('code', 'plan_user_limit_reached');
    }

    public function test_standard_plan_allows_additional_users(): void
    {
        $owner = User::factory()->create();
        $token = $owner->createToken('test')->plainTextToken;
        $farm = $this->createSubscribedFarm($owner, $token)['farm'];
        $this->putFarmOnPlan($farm, SubscriptionPlan::STANDARD);

        $other = User::factory()->create();

        $response = $this->withToken($token)->postJson("/api/farms/{$farm->id}/users", [
            'email' => $other->email,
            'role' => 'worker',
        ]);

        $response->assertOk();
        $this->assertTrue($farm->users()->where('users.id', $other->id)->exists());
    }

    public function test_basic_plan_blocks_a_second_active_batch(): void
    {
        $owner = User::factory()->create();
        $token = $owner->createToken('test')->plainTextToken;
        $farm = $this->createSubscribedFarm($owner, $token)['farm'];
        $this->putFarmOnPlan($farm, SubscriptionPlan::BASIC);

        $deps = $this->flockDependencies($farm);
        $this->createActiveFlock($farm, $deps);

        $response = $this->withToken($token)->postJson("/api/farms/{$farm->id}/flocks", [
            'farm_id' => $farm->id,
            'house_id' => $deps['house']->id,
            'poultry_type_id' => $deps['type']->id,
            'flock_stage_id' => $deps['stage']->id,
            'name' => 'Second batch',
            'breed' => 'Broiler',
            'source' => 'Hatchery',
            'quantity' => 10,
            'arrival_date' => now()->toDateString(),
            'arrival_age_days' => 1,
        ]);

        $response->assertStatus(402)
            ->assertJsonPath('code', 'plan_batch_limit_reached');
    }

    public function test_standard_plan_allows_unlimited_active_batches(): void
    {
        $owner = User::factory()->create();
        $token = $owner->createToken('test')->plainTextToken;
        $farm = $this->createSubscribedFarm($owner, $token)['farm'];
        $this->putFarmOnPlan($farm, SubscriptionPlan::STANDARD);

        $deps = $this->flockDependencies($farm);
        $this->createActiveFlock($farm, $deps);

        $response = $this->withToken($token)->postJson("/api/farms/{$farm->id}/flocks", [
            'farm_id' => $farm->id,
            'house_id' => $deps['house']->id,
            'poultry_type_id' => $deps['type']->id,
            'flock_stage_id' => $deps['stage']->id,
            'name' => 'Second batch',
            'breed' => 'Broiler',
            'source' => 'Hatchery',
            'quantity' => 10,
            'arrival_date' => now()->toDateString(),
            'arrival_age_days' => 1,
        ]);

        $response->assertCreated();
        $this->assertSame(2, $farm->flocks()->where('status', 'active')->count());
    }
}
