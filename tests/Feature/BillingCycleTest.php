<?php

namespace Tests\Feature;

use App\Models\FarmSubscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Support\BillingCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithSubscriptions;
use Tests\TestCase;

class BillingCycleTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithSubscriptions;

    private const SECRET = 'sk_test_billing_cycles';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'paystack.secret_key' => self::SECRET,
            'paystack.base_url' => 'https://api.paystack.co',
        ]);
    }

    public function test_longer_cycles_are_discounted_and_rounded_to_whole_naira(): void
    {
        $premium = $this->plan(SubscriptionPlan::PREMIUM); // ₦15,000 a month

        $this->assertSame(1500000, BillingCycle::priceKobo($premium, BillingCycle::MONTHLY));
        $this->assertSame(8550000, BillingCycle::priceKobo($premium, BillingCycle::BIANNUAL)); // 90,000 less 5%
        $this->assertSame(16200000, BillingCycle::priceKobo($premium, BillingCycle::ANNUAL)); // 180,000 less 10%

        // Odd prices still land on whole naira.
        $this->assertSame(0, BillingCycle::priceKoboFor(333333, BillingCycle::BIANNUAL) % 100);
    }

    public function test_discounts_can_be_changed_in_config(): void
    {
        config(['subscription.billing_cycle_discounts.annual' => 20]);

        $this->assertSame(14400000, BillingCycle::priceKobo($this->plan(SubscriptionPlan::PREMIUM), BillingCycle::ANNUAL));
    }

    public function test_plan_catalog_lists_every_billing_cycle(): void
    {
        $token = User::factory()->create()->createToken('test')->plainTextToken;
        $response = $this->withToken($token)->getJson('/api/subscription/plans');

        $response->assertOk()
            ->assertJsonPath('data.0.slug', 'basic')
            ->assertJsonPath('data.0.billing_cycles.0.cycle', 'monthly')
            ->assertJsonPath('data.0.billing_cycles.0.price', 5000)
            ->assertJsonPath('data.0.billing_cycles.1.cycle', 'biannual')
            ->assertJsonPath('data.0.billing_cycles.1.months', 6)
            ->assertJsonPath('data.0.billing_cycles.1.price', 28500)
            ->assertJsonPath('data.0.billing_cycles.1.savings', 1500)
            ->assertJsonPath('data.0.billing_cycles.2.cycle', 'annual')
            ->assertJsonPath('data.0.billing_cycles.2.price', 54000)
            ->assertJsonPath('data.0.billing_cycles.2.monthly_equivalent', 4500);
    }

    public function test_yearly_checkout_charges_the_discounted_total_on_a_yearly_paystack_plan(): void
    {
        Http::fake([
            'api.paystack.co/plan' => Http::response(['status' => true, 'data' => ['plan_code' => 'PLN_premium_year']]),
            'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => [
                'authorization_url' => 'https://checkout.paystack.com/abc',
                'access_code' => 'abc',
                'reference' => 'ref_year',
            ]]),
        ]);

        [$token, $farm] = $this->ownerFarm();

        $response = $this->withToken($token)->postJson("/api/farms/{$farm->id}/subscription/checkout", [
            'plan_slug' => 'premium',
            'billing_cycle' => 'annual',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.authorization_url', 'https://checkout.paystack.com/abc')
            ->assertJsonPath('data.amount_kobo', 16200000)
            ->assertJsonPath('data.billing_cycle', 'annual');

        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/plan')
            && $r['interval'] === 'annually'
            && $r['amount'] === 16200000);
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/transaction/initialize')
            && $r['amount'] === 16200000
            && $r['plan'] === 'PLN_premium_year'
            && $r['metadata']['billing_cycle'] === 'annual');

        $this->assertDatabaseHas('subscription_transactions', [
            'farm_id' => $farm->id,
            'event' => 'checkout.initialized',
            'billing_cycle' => 'annual',
            'amount_kobo' => 16200000,
        ]);
        $this->assertSame('PLN_premium_year', $this->plan('premium')->fresh()->paystackPlanCodeFor('annual'));
    }

    public function test_the_paystack_plan_is_reused_and_recreated_after_a_price_change(): void
    {
        Http::fake([
            'api.paystack.co/plan' => Http::sequence()
                ->push(['status' => true, 'data' => ['plan_code' => 'PLN_first']])
                ->push(['status' => true, 'data' => ['plan_code' => 'PLN_second']]),
            'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'u', 'access_code' => 'a', 'reference' => 'r']]),
        ]);

        [$token, $farm] = $this->ownerFarm();
        $checkout = fn () => $this->withToken($token)->postJson("/api/farms/{$farm->id}/subscription/checkout", [
            'plan_slug' => 'standard',
            'billing_cycle' => 'biannual',
        ])->assertOk();

        $checkout();
        $checkout();
        Http::assertSentCount(3); // one plan + two checkouts

        $this->plan('standard')->update(['price_kobo' => 1200000]);
        $checkout();

        $this->assertSame('PLN_second', $this->plan('standard')->fresh()->paystackPlanCodeFor('biannual'));
        // The old code still resolves for renewals already on it.
        $this->assertSame('biannual', SubscriptionPlan::findByPaystackCode('PLN_second')[1]);
    }

    public function test_checkout_still_works_when_paystack_cannot_create_the_plan(): void
    {
        Http::fake([
            'api.paystack.co/plan' => Http::response(['status' => false, 'message' => 'nope'], 400),
            'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'u', 'access_code' => 'a', 'reference' => 'r']]),
        ]);

        [$token, $farm] = $this->ownerFarm();

        $this->withToken($token)->postJson("/api/farms/{$farm->id}/subscription/checkout", [
            'plan_slug' => 'basic',
            'billing_cycle' => 'biannual',
        ])->assertOk()->assertJsonPath('data.amount_kobo', 2850000);

        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/transaction/initialize')
            && ! isset($r['plan'])
            && $r['amount'] === 2850000);
    }

    public function test_an_unknown_cycle_is_rejected(): void
    {
        [$token, $farm] = $this->ownerFarm();

        $this->withToken($token)->postJson("/api/farms/{$farm->id}/subscription/checkout", [
            'plan_slug' => 'basic',
            'billing_cycle' => 'weekly',
        ])->assertStatus(422);
    }

    public function test_yearly_payment_opens_a_twelve_month_period(): void
    {
        [, $farm] = $this->ownerFarm();
        $paidAt = now()->startOfDay();

        $this->postWebhook([
            'event' => 'charge.success',
            'data' => [
                'reference' => 'ref_year',
                'amount' => 16200000,
                'status' => 'success',
                'paid_at' => $paidAt->toIso8601String(),
                'metadata' => ['farm_id' => $farm->id, 'plan_slug' => 'premium', 'billing_cycle' => 'annual'],
            ],
        ])->assertOk();

        $subscription = FarmSubscription::where('farm_id', $farm->id)->first();
        $this->assertSame(FarmSubscription::STATUS_ACTIVE, $subscription->status);
        $this->assertSame('annual', $subscription->billing_cycle);
        $this->assertTrue($subscription->current_period_end->equalTo($paidAt->copy()->addYear()));
        $this->assertSame('annual', SubscriptionTransaction::where('event', 'charge.success')->first()->billing_cycle);

        $this->withToken($farm->owner->createToken('t')->plainTextToken)
            ->getJson("/api/farms/{$farm->id}/subscription")
            ->assertJsonPath('data.billing_cycle', 'annual')
            ->assertJsonPath('data.billing_cycle_label', 'Yearly')
            ->assertJsonPath('data.billing_amount', 162000);
    }

    public function test_a_renewal_without_metadata_keeps_the_cycle_from_its_paystack_plan(): void
    {
        [, $farm] = $this->ownerFarm();
        $plan = $this->plan('standard');
        $plan->rememberPaystackPlanCode('biannual', 'PLN_std_6m');
        $subscription = $this->putFarmOnPlan($farm, 'standard');
        $subscription->update(['paystack_subscription_code' => 'SUB_6m']);

        $this->postWebhook([
            'event' => 'charge.success',
            'data' => [
                'reference' => 'ref_renew',
                'amount' => 5700000,
                'status' => 'success',
                'paid_at' => now()->toIso8601String(),
                'subscription_code' => 'SUB_6m',
                'plan' => ['plan_code' => 'PLN_std_6m', 'interval' => 'biannually'],
            ],
        ])->assertOk();

        $subscription->refresh();
        $this->assertSame('biannual', $subscription->billing_cycle);
        $this->assertSame($plan->id, $subscription->subscription_plan_id);
        $this->assertTrue($subscription->current_period_end->greaterThan(now()->addMonths(5)));
    }

    public function test_changing_cycle_through_change_plan_needs_a_checkout(): void
    {
        [$token, $farm] = $this->ownerFarm();
        $this->putFarmOnPlan($farm, 'standard');

        $this->withToken($token)->postJson("/api/farms/{$farm->id}/subscription/change-plan", [
            'plan_slug' => 'basic',
            'billing_cycle' => 'annual',
        ])->assertStatus(422)->assertJsonPath('errors.code', 'checkout_required');
    }

    /**
     * @return array{0:string,1:\App\Models\Farm}
     */
    private function ownerFarm(): array
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $farm = $this->createSubscribedFarm($user, $token)['farm'];

        return [$token, $farm];
    }

    private function postWebhook(array $payload)
    {
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);

        return $this->call('POST', '/api/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $raw, self::SECRET),
        ], $raw);
    }
}
