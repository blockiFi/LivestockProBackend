<?php

namespace App\Http\Controllers\Api;

use App\Models\Farm;
use App\Models\FarmSubscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTransaction;
use App\Services\FarmEntitlementService;
use App\Services\FarmSubscriptionService;
use App\Services\PaystackService;
use App\Support\BillingCycle;
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
        [$resolvedPlan, $codeCycle] = $this->resolvePlan($data);
        $plan = $resolvedPlan ?? $subscription->plan;
        $cycle = $this->resolveCycle($data, $codeCycle) ?? $subscription->billing_cycle;

        match ($event) {
            'charge.success', 'subscription.create' => $this->handlePaymentSuccess($subscription, $plan, $cycle, $data),
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
            billingCycle: $cycle,
        );

        return $this->sendResponse(null, 'Webhook processed');
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function handlePaymentSuccess(FarmSubscription $subscription, ?SubscriptionPlan $plan, string $cycle, array $data): void
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
            : $periodStart->copy()->addMonthsNoOverflow(BillingCycle::months($cycle));

        $this->subscriptions->activate($subscription, $plan, $periodStart, $periodEnd, $cycle);
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
     * The plan a payment was for, plus the cycle its Paystack plan code bills on.
     *
     * @param  array<string,mixed>  $data
     * @return array{0:?SubscriptionPlan,1:?string}
     */
    private function resolvePlan(array $data): array
    {
        $code = is_string($data['plan'] ?? null)
            ? $data['plan']
            : (data_get($data, 'plan.plan_code') ?? data_get($data, 'plan_code'));
        $byCode = is_string($code) && $code !== '' ? SubscriptionPlan::findByPaystackCode($code) : null;

        if ($slug = data_get($data, 'metadata.plan_slug')) {
            return [SubscriptionPlan::where('slug', $slug)->first(), $byCode[1] ?? null];
        }

        return $byCode ? [$byCode[0], $byCode[1]] : [null, null];
    }

    /**
     * Checkout metadata wins, then the Paystack plan's own interval.
     * Renewal charges carry no metadata, so the plan is what tells them apart.
     *
     * @param  array<string,mixed>  $data
     */
    private function resolveCycle(array $data, ?string $codeCycle): ?string
    {
        $fromMetadata = data_get($data, 'metadata.billing_cycle');

        if (BillingCycle::isValid($fromMetadata)) {
            return $fromMetadata;
        }

        return $codeCycle ?? BillingCycle::fromPaystackInterval(data_get($data, 'plan.interval'));
    }
}
