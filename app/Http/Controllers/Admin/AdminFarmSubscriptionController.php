<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionWaiver;
use App\Services\Admin\AdminSubscriptionService;
use App\Traits\LogsAdminAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminFarmSubscriptionController extends ApiController
{
    use LogsAdminAction;

    public function __construct(private readonly AdminSubscriptionService $subscriptions)
    {
    }

    public function show(Farm $farm): JsonResponse
    {
        return $this->sendResponse($this->subscriptions->detail($farm), 'Farm subscription retrieved');
    }

    public function waivers(Farm $farm): JsonResponse
    {
        return $this->sendResponse($this->subscriptions->waiverHistory($farm), 'Waiver history retrieved');
    }

    public function grantWaiver(Request $request, Farm $farm): JsonResponse
    {
        $validated = $request->validate([
            'plan_slug' => 'required|string|exists:subscription_plans,slug',
            'months' => 'required|integer|min:1|max:'.SubscriptionWaiver::MAX_MONTHS,
            'reason' => 'nullable|string|max:1000',
        ]);

        $plan = SubscriptionPlan::where('slug', $validated['plan_slug'])->firstOrFail();

        $subscription = $this->subscriptions->grantWaiver(
            $farm,
            $plan,
            (int) $validated['months'],
            $validated['reason'] ?? null,
            $request->user()
        );

        $this->logAdminAction($request, 'subscription.waiver.grant', 'farm', $farm->id, null, [
            'plan_slug' => $plan->slug,
            'months' => (int) $validated['months'],
            'waived_until' => $subscription->waived_until?->toIso8601String(),
            'reason' => $validated['reason'] ?? null,
        ]);

        return $this->sendResponse($this->subscriptions->detail($farm->fresh()), 'Subscription waiver granted');
    }

    public function revokeWaiver(Request $request, Farm $farm): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        $this->subscriptions->revokeWaiver($farm, $request->user(), $validated['reason'] ?? null);

        $this->logAdminAction($request, 'subscription.waiver.revoke', 'farm', $farm->id, null, [
            'reason' => $validated['reason'] ?? null,
        ]);

        return $this->sendResponse($this->subscriptions->detail($farm->fresh()), 'Subscription waiver revoked');
    }

    public function assignPlan(Request $request, Farm $farm): JsonResponse
    {
        $validated = $request->validate([
            'plan_slug' => 'required|string|exists:subscription_plans,slug',
        ]);

        $plan = SubscriptionPlan::where('slug', $validated['plan_slug'])->firstOrFail();

        $this->subscriptions->assignPlan($farm, $plan, $request->user());

        $this->logAdminAction($request, 'subscription.plan.assign', 'farm', $farm->id, null, [
            'plan_slug' => $plan->slug,
        ]);

        return $this->sendResponse($this->subscriptions->detail($farm->fresh()), 'Subscription plan assigned');
    }

    public function extendTrial(Request $request, Farm $farm): JsonResponse
    {
        $validated = $request->validate([
            'days' => 'required|integer|min:1|max:365',
        ]);

        $this->subscriptions->extendTrial($farm, (int) $validated['days'], $request->user());

        $this->logAdminAction($request, 'subscription.trial.extend', 'farm', $farm->id, null, [
            'days' => (int) $validated['days'],
        ]);

        return $this->sendResponse($this->subscriptions->detail($farm->fresh()), 'Trial extended');
    }

    public function kpis(): JsonResponse
    {
        return $this->sendResponse($this->subscriptions->kpis(), 'Subscription KPIs retrieved');
    }
}
