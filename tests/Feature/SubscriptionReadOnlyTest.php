<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSubscriptions;
use Tests\TestCase;

class SubscriptionReadOnlyTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithSubscriptions;

    public function test_reads_are_allowed_after_the_subscription_lapses(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $farm = $this->createSubscribedFarm($user, $token)['farm'];
        $this->expireFarmSubscription($farm);

        $this->withToken($token)->getJson("/api/farms/{$farm->id}")
            ->assertOk();

        $this->withToken($token)->getJson("/api/farms/{$farm->id}/subscription")
            ->assertOk()
            ->assertJsonPath('data.is_read_only', true);
    }

    public function test_writes_are_blocked_after_the_subscription_lapses(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $farm = $this->createSubscribedFarm($user, $token)['farm'];
        $this->expireFarmSubscription($farm);

        $response = $this->withToken($token)->putJson("/api/farms/{$farm->id}", [
            'name' => 'Renamed after lapse',
        ]);

        $response->assertStatus(402)
            ->assertJsonPath('code', 'subscription_read_only');

        $this->assertDatabaseMissing('farms', [
            'id' => $farm->id,
            'name' => 'Renamed after lapse',
        ]);
    }
}
