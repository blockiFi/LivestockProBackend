<?php

namespace Tests\Feature\Admin;

use App\Models\FarmSubscription;
use App\Models\SubscriptionPlan;
use App\Services\FarmEntitlementService;

class AdminBillingCycleTest extends AdminTestCase
{
    public function test_mrr_spreads_longer_cycles_over_their_months(): void
    {
        $premium = SubscriptionPlan::where('slug', 'premium')->first();
        $subscription = app(FarmEntitlementService::class)->subscription($this->farm);
        $subscription->update([
            'subscription_plan_id' => $premium->id,
            'status' => FarmSubscription::STATUS_ACTIVE,
            'billing_cycle' => 'annual',
            'current_period_end' => now()->addYear(),
        ]);

        // ₦162,000 a year is ₦13,500 a month.
        $this->getJson('/api/admin/subscriptions/kpis', $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.mrr', 13500)
            ->assertJsonPath('data.by_cycle.2.cycle', 'annual')
            ->assertJsonPath('data.by_cycle.2.active', 1);

        $overview = $this->getJson('/api/admin/revenue/overview', $this->adminHeaders())->assertOk();
        $mix = collect($overview->json('data.plan_mix'))->firstWhere('slug', 'premium');
        $this->assertEquals(13500, $mix['mrr']);
        $this->assertSame(1, $mix['paying_by_cycle']['annual']);

        $this->getJson('/api/admin/revenue/subscriptions?billing_cycle=annual', $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.billing_cycle', 'annual')
            ->assertJsonPath('data.data.0.billing_amount_kobo', 16200000);

        $this->getJson('/api/admin/revenue/subscriptions?billing_cycle=monthly', $this->adminHeaders())
            ->assertJsonPath('data.total', 0);
    }
}
