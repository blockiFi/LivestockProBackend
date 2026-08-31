<?php

namespace Tests\Feature;

use App\Models\FarmSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSubscriptions;
use Tests\TestCase;

class PaystackWebhookTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithSubscriptions;

    private const SECRET = 'sk_test_subscription_webhook';

    protected function setUp(): void
    {
        parent::setUp();
        config(['paystack.secret_key' => self::SECRET]);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $response = $this->postJson('/api/webhooks/paystack', [
            'event' => 'charge.success',
            'data' => [],
        ], ['X-Paystack-Signature' => 'not-valid']);

        $response->assertStatus(401);
    }

    public function test_charge_success_activates_the_farm_subscription(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $farm = $this->createSubscribedFarm($user, $token)['farm'];

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' => 'ref_abc',
                'amount' => 1000000,
                'status' => 'success',
                'paid_at' => now()->toIso8601String(),
                'next_payment_date' => now()->addMonth()->toIso8601String(),
                'subscription_code' => 'SUB_test',
                'email_token' => 'email-token',
                'customer' => ['customer_code' => 'CUS_test'],
                'metadata' => [
                    'farm_id' => $farm->id,
                    'plan_slug' => 'standard',
                ],
            ],
        ];

        $response = $this->postWebhook($payload);

        $response->assertOk();

        $subscription = FarmSubscription::where('farm_id', $farm->id)->first();
        $this->assertNotNull($subscription);
        $this->assertSame(FarmSubscription::STATUS_ACTIVE, $subscription->status);
        $this->assertSame($this->plan(SubscriptionPlan::STANDARD)->id, $subscription->subscription_plan_id);
        $this->assertSame('SUB_test', $subscription->paystack_subscription_code);
        $this->assertDatabaseHas('subscription_transactions', [
            'farm_id' => $farm->id,
            'source' => 'paystack_webhook',
            'event' => 'charge.success',
            'reference' => 'ref_abc',
        ]);
    }

    public function test_payment_failed_moves_the_farm_to_past_due(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $farm = $this->createSubscribedFarm($user, $token)['farm'];
        $this->putFarmOnPlan($farm, SubscriptionPlan::STANDARD);

        $payload = [
            'event' => 'invoice.payment_failed',
            'data' => [
                'metadata' => ['farm_id' => $farm->id],
            ],
        ];

        $this->postWebhook($payload)->assertOk();

        $this->assertDatabaseHas('farm_subscriptions', [
            'farm_id' => $farm->id,
            'status' => FarmSubscription::STATUS_PAST_DUE,
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function postWebhook(array $payload)
    {
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha512', $raw, self::SECRET);

        return $this->call(
            'POST',
            '/api/webhooks/paystack',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
            ],
            $raw
        );
    }
}
