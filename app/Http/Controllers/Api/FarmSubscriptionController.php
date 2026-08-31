<?php

namespace App\Http\Controllers\Api;

use App\Models\Farm;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTransaction;
use App\Services\FarmEntitlementService;
use App\Services\FarmSubscriptionService;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\PermissionRegistrar;

class FarmSubscriptionController extends ApiController
{
    public function __construct(
        private readonly FarmEntitlementService $entitlements,
        private readonly FarmSubscriptionService $subscriptions,
        private readonly PaystackService $paystack,
    ) {
    }

    /**
     * Public plan catalog for the billing page and marketing site.
     */
    public function plans(): JsonResponse
    {
        $plans = SubscriptionPlan::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (SubscriptionPlan $plan) => [
                'slug' => $plan->slug,
                'name' => $plan->name,
                'description' => $plan->description,
                'price' => $plan->price,
                'price_kobo' => $plan->price_kobo,
                'currency' => $plan->currency,
                'max_users' => $plan->max_users,
                'max_active_flocks' => $plan->max_active_flocks,
                'ai_enabled' => $plan->ai_enabled,
            ]);

        return $this->sendResponse($plans, 'Subscription plans retrieved successfully');
    }

    public function show(Request $request, Farm $farm): JsonResponse
    {
        if ($response = $this->authorizeView($farm)) {
            return $response;
        }

        return $this->sendResponse(
            $this->entitlements->summary($farm),
            'Farm subscription retrieved successfully'
        );
    }

    public function transactions(Request $request, Farm $farm): JsonResponse
    {
        if ($response = $this->authorizeManage($farm)) {
            return $response;
        }

        $transactions = SubscriptionTransaction::with('plan:id,slug,name')
            ->where('farm_id', $farm->id)
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return $this->sendResponse($transactions, 'Subscription transactions retrieved successfully');
    }

    /**
     * Begin a Paystack checkout for the requested plan.
     */
    public function checkout(Request $request, Farm $farm): JsonResponse
    {
        if ($response = $this->authorizeManage($farm)) {
            return $response;
        }

        $validator = Validator::make($request->all(), [
            'plan_slug' => 'required|string|exists:subscription_plans,slug',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation Error', $validator->errors()->all());
        }

        $plan = SubscriptionPlan::where('slug', $request->plan_slug)->where('is_active', true)->first();

        if (! $plan) {
            return $this->sendError('That plan is not available', [], 422);
        }

        if ($blockers = $this->subscriptions->downgradeBlockers($farm, $plan)) {
            return $this->sendError('This farm exceeds the limits of that plan', $blockers, 422);
        }

        if (! $this->paystack->isConfigured()) {
            return $this->sendError('Online payment is not configured. Please contact support to activate this plan.', [], 503);
        }

        $checkout = $this->paystack->initializeSubscription($farm, $plan, $request->user());

        if ($checkout === null) {
            return $this->sendError('Could not start checkout with the payment provider. Please try again.', [], 502);
        }

        $this->subscriptions->recordTransaction(
            farm: $farm,
            plan: $plan,
            source: SubscriptionTransaction::SOURCE_CHECKOUT,
            event: 'checkout.initialized',
            payload: ['plan_slug' => $plan->slug],
            actor: $request->user(),
            amountKobo: $plan->price_kobo,
            reference: $checkout['reference'] ?: null,
            status: 'pending',
        );

        return $this->sendResponse($checkout, 'Checkout session created successfully');
    }

    /**
     * Move an already-paying farm between plans.
     */
    public function changePlan(Request $request, Farm $farm): JsonResponse
    {
        if ($response = $this->authorizeManage($farm)) {
            return $response;
        }

        $validator = Validator::make($request->all(), [
            'plan_slug' => 'required|string|exists:subscription_plans,slug',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation Error', $validator->errors()->all());
        }

        $plan = SubscriptionPlan::where('slug', $request->plan_slug)->where('is_active', true)->first();

        if (! $plan) {
            return $this->sendError('That plan is not available', [], 422);
        }

        if ($blockers = $this->subscriptions->downgradeBlockers($farm, $plan)) {
            return $this->sendError('This farm exceeds the limits of that plan', $blockers, 422);
        }

        $subscription = $this->entitlements->subscription($farm);
        $currentPlan = $this->entitlements->plan($farm);

        // A farm without a paid period has nothing to switch — send it to checkout.
        if ($plan->price_kobo > $currentPlan->price_kobo && ! $subscription->hasPaystackSubscription() && ! $subscription->isWaived()) {
            return $this->sendError(
                'Start a checkout to move onto a higher plan.',
                ['code' => 'checkout_required'],
                422
            );
        }

        $this->subscriptions->changePlan($farm, $plan, SubscriptionTransaction::SOURCE_CHECKOUT, $request->user());

        return $this->sendResponse(
            $this->entitlements->summary($farm->fresh()),
            'Subscription plan updated successfully'
        );
    }

    public function cancel(Request $request, Farm $farm): JsonResponse
    {
        if ($response = $this->authorizeManage($farm)) {
            return $response;
        }

        $subscription = $this->entitlements->subscription($farm);

        if ($subscription->hasPaystackSubscription() && $subscription->paystack_email_token) {
            $this->paystack->disableSubscription(
                $subscription->paystack_subscription_code,
                $subscription->paystack_email_token
            );
        }

        $this->subscriptions->cancel($farm, $request->user());

        return $this->sendResponse(
            $this->entitlements->summary($farm->fresh()),
            'Subscription cancelled. Access continues until the end of the paid period.'
        );
    }

    private function authorizeView(Farm $farm): ?JsonResponse
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        $user = auth()->user();

        if ($user->can('manage billing', 'api', $farm->id) || $user->can('view farm', 'api', $farm->id)) {
            return null;
        }

        return $this->sendError('You do not have permission to view billing for this farm', [], 403);
    }

    private function authorizeManage(Farm $farm): ?JsonResponse
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (auth()->user()->can('manage billing', 'api', $farm->id)) {
            return null;
        }

        return $this->sendError('You do not have permission to manage billing for this farm', [], 403);
    }
}
