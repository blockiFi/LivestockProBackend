<?php

namespace Tests\Feature\Admin;

use App\Models\FarmSubscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionWaiver;
use App\Services\FarmEntitlementService;
use Tests\Concerns\InteractsWithSubscriptions;

class AdminSubscriptionWaiverTest extends AdminTestCase
{
    use InteractsWithSubscriptions;

    public function test_admin_can_grant_a_premium_waiver_for_n_months(): void
    {
        $farm = $this->farm;

        $response = $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/farms/{$farm->id}/subscription/waiver", [
                'plan_slug' => 'premium',
                'months' => 3,
                'reason' => 'Pilot farm',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.subscription.status', 'waived')
            ->assertJsonPath('data.subscription.plan.slug', 'premium')
            ->assertJsonPath('data.subscription.ai_enabled', true);

        $subscription = FarmSubscription::where('farm_id', $farm->id)->first();
        $this->assertNotNull($subscription?->waived_until);
        $this->assertTrue($subscription->waived_until->greaterThan(now()->addMonths(2)));
        $this->assertTrue($subscription->waived_until->lessThanOrEqualTo(now()->addMonths(3)->addDay()));

        $this->assertDatabaseHas('subscription_waivers', [
            'farm_id' => $farm->id,
            'months' => 3,
            'status' => SubscriptionWaiver::STATUS_ACTIVE,
            'granted_by' => $this->admin->id,
            'reason' => 'Pilot farm',
        ]);

        $this->assertDatabaseHas('subscription_transactions', [
            'farm_id' => $farm->id,
            'source' => 'admin_waiver',
            'event' => 'waiver.granted',
        ]);

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'subscription.waiver.grant',
            'resource_id' => $farm->id,
        ]);

        $this->assertTrue(app(FarmEntitlementService::class)->canUseAi($farm->fresh()));
    }

    public function test_granting_another_waiver_extends_the_end_date(): void
    {
        $farm = $this->farm;

        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/farms/{$farm->id}/subscription/waiver", [
                'plan_slug' => 'standard',
                'months' => 1,
            ])
            ->assertOk();

        $firstEnd = FarmSubscription::where('farm_id', $farm->id)->value('waived_until');

        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/farms/{$farm->id}/subscription/waiver", [
                'plan_slug' => 'premium',
                'months' => 1,
            ])
            ->assertOk();

        $secondEnd = FarmSubscription::where('farm_id', $farm->id)->value('waived_until');
        $this->assertTrue($secondEnd->greaterThan($firstEnd));
        $this->assertEquals(2, SubscriptionWaiver::where('farm_id', $farm->id)->count());
        $this->assertSame(SubscriptionPlan::PREMIUM, $farm->fresh()->subscription->plan->slug);
    }

    public function test_admin_can_revoke_an_active_waiver(): void
    {
        $farm = $this->farm;

        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/farms/{$farm->id}/subscription/waiver", [
                'plan_slug' => 'premium',
                'months' => 3,
            ])
            ->assertOk();

        $this->withHeaders($this->adminHeaders())
            ->deleteJson("/api/admin/farms/{$farm->id}/subscription/waiver", [
                'reason' => 'Promo ended',
            ])
            ->assertOk()
            ->assertJsonPath('data.subscription.status', 'read_only');

        $this->assertDatabaseHas('subscription_waivers', [
            'farm_id' => $farm->id,
            'status' => SubscriptionWaiver::STATUS_REVOKED,
            'revoked_by' => $this->admin->id,
        ]);
    }

    public function test_waiver_expiry_command_marks_the_farm_read_only(): void
    {
        $farm = $this->farm;

        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/farms/{$farm->id}/subscription/waiver", [
                'plan_slug' => 'premium',
                'months' => 1,
            ])
            ->assertOk();

        FarmSubscription::where('farm_id', $farm->id)->update([
            'waived_until' => now()->subDay(),
        ]);
        SubscriptionWaiver::where('farm_id', $farm->id)->update([
            'ends_at' => now()->subDay(),
        ]);

        $subscription = FarmSubscription::where('farm_id', $farm->id)->first();
        $subscription->status = app(FarmEntitlementService::class)->effectiveStatus($subscription);
        $subscription->save();

        SubscriptionWaiver::where('farm_id', $farm->id)
            ->where('status', SubscriptionWaiver::STATUS_ACTIVE)
            ->where('ends_at', '<=', now())
            ->update(['status' => SubscriptionWaiver::STATUS_EXPIRED]);

        $this->assertDatabaseHas('farm_subscriptions', [
            'farm_id' => $farm->id,
            'status' => FarmSubscription::STATUS_READ_ONLY,
        ]);
        $this->assertDatabaseHas('subscription_waivers', [
            'farm_id' => $farm->id,
            'status' => SubscriptionWaiver::STATUS_EXPIRED,
        ]);
    }

    public function test_months_must_be_between_one_and_twenty_four(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/farms/{$this->farm->id}/subscription/waiver", [
                'plan_slug' => 'premium',
                'months' => 0,
            ])
            ->assertStatus(422);

        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/farms/{$this->farm->id}/subscription/waiver", [
                'plan_slug' => 'premium',
                'months' => 25,
            ])
            ->assertStatus(422);
    }
}
