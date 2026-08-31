<?php

namespace App\Http\Controllers\Api;

use App\Models\Farm;
use App\Models\FarmSubscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTransaction;
use App\Services\FarmEntitlementService;
use App\Services\FarmSubscriptionService;
use App\Services\PaystackService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaystackWebhookController extends ApiController
{
    public function __construct(
        private readonly PaystackService $paystack,
        private readonly FarmEntitlementService $entitlements,
        private readonly FarmSubscriptionService $subscriptions,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        if (! $this->paystack->verifyWebhookSignature($request->getContent(), $request->header('x-paystack-signature'))) {
            return $this->sendError('Invalid signature', [], 401);
        }

        $event = (string) $request->input('event');
        $data = (array) $request->input('data', []);

        $farm = $this->resolveFarm($data);

        if (! $farm) {
            // Nothing to reconcile, but acknowledge so Paystack stops retrying.
            Log::info('Paystack webhook without a resolvable farm', ['event' => $event]);

            return $this->sendResponse(null, 'Webhook acknowledged');
        }

        $subscription = $this->entitlements->subscription($farm);
        $plan = $this->resolvePlan($data) ?? $subscription->plan;

        match ($event) {
            'charge.success', 'subscription.create' => $this->handlePaymentSuccess($subscription, $plan, $data),
            'invoice.payment_failed', 'subscription.not_renew' => $this->subscriptions->markPastDue($subscription),
            'subscription.disable' => $this->handleDisable($subscription),
            default => null,
        };

        $this->subscriptions->recordTransaction(
            farm: $farm,
            plan: $plan,
            source: SubscriptionTransaction::SOURCE_PAYSTACK_WEBHOOK,
            event: $event,
            payload: $data,
            amountKobo: isset($data['amount']) ? (int) $data['amount'] : null,
            reference: $data['reference'] ?? ($data['subscription_code'] ?? null),
            status: $data['status'] ?? null,
        );

        return $this->sendResponse(null, 'Webhook processed');
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function handlePaymentSuccess(FarmSubscription $subscription, ?SubscriptionPlan $plan, array $data): void
    {
        if (! $plan) {
            return;
        }

        if (isset($data['customer']['customer_code'])) {
            $subscription->paystack_customer_code = $data['customer']['customer_code'];
        }

        if (isset($data['subscription_code'])) {
            $subscription->paystack_subscription_code = $data['subscription_code'];
        }

        if (isset($data['email_token'])) {
            $subscription->paystack_email_token = $data['email_token'];
        }

        $periodStart = isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now();
        $periodEnd = isset($data['next_payment_date'])
            ? Carbon::parse($data['next_payment_date'])
            : $periodStart->copy()->addMonth();

        $this->subscriptions->activate($subscription, $plan, $periodStart, $periodEnd);
    }

    private function handleDisable(FarmSubscription $subscription): void
    {
        $subscription->status = FarmSubscription::STATUS_CANCELLED;
        $subscription->cancelled_at = now();
        $subscription->ends_at = $subscription->current_period_end ?? now();
        $subscription->save();
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function resolveFarm(array $data): ?Farm
    {
        $farmId = $data['metadata']['farm_id'] ?? null;

        if ($farmId) {
            return Farm::find($farmId);
        }

        foreach (['subscription_code', 'customer.customer_code'] as $path) {
            $value = data_get($data, $path);

            if (! $value) {
                continue;
            }

            $column = $path === 'subscription_code' ? 'paystack_subscription_code' : 'paystack_customer_code';
            $subscription = FarmSubscription::where($column, $value)->first();

            if ($subscription) {
                return $subscription->farm;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function resolvePlan(array $data): ?SubscriptionPlan
    {
        if ($slug = data_get($data, 'metadata.plan_slug')) {
            return SubscriptionPlan::where('slug', $slug)->first();
        }

        if ($code = data_get($data, 'plan.plan_code') ?? data_get($data, 'plan_code')) {
            return SubscriptionPlan::where('paystack_plan_code', $code)->first();
        }

        return null;
    }
}
