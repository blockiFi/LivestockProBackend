<?php

namespace App\Services\Admin;

use App\Models\Farm;
use App\Models\FarmSubscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTransaction;
use App\Models\SubscriptionWaiver;
use App\Models\User;
use App\Services\FarmEntitlementService;
use App\Services\FarmSubscriptionService;
use App\Services\PaystackService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Platform-admin operations on farm subscriptions: waivers, plan overrides and
 * trial extensions.
 */
class AdminSubscriptionService
{
    public function __construct(
        private readonly FarmEntitlementService $entitlements,
        private readonly FarmSubscriptionService $subscriptions,
        private readonly PaystackService $paystack,
    ) {
    }

    /**
     * Grant (or extend) a free period on a chosen plan tier.
     *
     * Stacking is intentional: extending an active waiver adds months onto the
     * existing end date rather than shortening it.
     */
    public function grantWaiver(
        Farm $farm,
        SubscriptionPlan $plan,
        int $months,
        ?string $reason,
        User $admin,
    ): FarmSubscription {
        return DB::transaction(function () use ($farm, $plan, $months, $reason, $admin) {
            $subscription = $this->entitlements->subscription($farm);

            $startsAt = $subscription->isWaived() ? $subscription->waived_until : now();
            $endsAt = $startsAt->copy()->addMonths($months);

            $subscription->subscription_plan_id = $plan->id;
            $subscription->status = FarmSubscription::STATUS_WAIVED;
            $subscription->waived_until = $endsAt;
            $subscription->grace_ends_at = null;
            $subscription->save();

            SubscriptionWaiver::create([
                'farm_id' => $farm->id,
                'farm_subscription_id' => $subscription->id,
                'subscription_plan_id' => $plan->id,
                'months' => $months,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'reason' => $reason,
                'granted_by' => $admin->id,
                'status' => SubscriptionWaiver::STATUS_ACTIVE,
            ]);

            // Pause Paystack so the farm is not charged during the free period.
            if ($subscription->hasPaystackSubscription() && $subscription->paystack_email_token) {
                $this->paystack->disableSubscription(
                    $subscription->paystack_subscription_code,
                    $subscription->paystack_email_token
                );
            }

            $this->subscriptions->recordTransaction(
                farm: $farm,
                plan: $plan,
                source: SubscriptionTransaction::SOURCE_ADMIN_WAIVER,
                event: 'waiver.granted',
                payload: [
                    'months' => $months,
                    'starts_at' => $startsAt->toIso8601String(),
                    'ends_at' => $endsAt->toIso8601String(),
                    'reason' => $reason,
                ],
                actor: $admin,
            );

            return $subscription->fresh('plan');
        });
    }

    /**
     * End an active waiver now. The farm falls back to whatever its Paystack
     * subscription supports, which may be read-only.
     */
    public function revokeWaiver(Farm $farm, User $admin, ?string $reason = null): FarmSubscription
    {
        return DB::transaction(function () use ($farm, $admin, $reason) {
            $subscription = $this->entitlements->subscription($farm);

            SubscriptionWaiver::where('farm_subscription_id', $subscription->id)
                ->where('status', SubscriptionWaiver::STATUS_ACTIVE)
                ->update([
                    'status' => SubscriptionWaiver::STATUS_REVOKED,
                    'revoked_at' => now(),
                    'revoked_by' => $admin->id,
                ]);

            $subscription->waived_until = now();
            $subscription->status = $subscription->current_period_end?->isFuture()
                ? FarmSubscription::STATUS_ACTIVE
                : FarmSubscription::STATUS_READ_ONLY;
            $subscription->save();

            if ($subscription->hasPaystackSubscription() && $subscription->paystack_email_token) {
                $this->paystack->enableSubscription(
                    $subscription->paystack_subscription_code,
                    $subscription->paystack_email_token
                );
            }

            $this->subscriptions->recordTransaction(
                farm: $farm,
                plan: $subscription->plan,
                source: SubscriptionTransaction::SOURCE_ADMIN_WAIVER,
                event: 'waiver.revoked',
                payload: ['reason' => $reason],
                actor: $admin,
            );

            return $subscription->fresh('plan');
        });
    }

    /**
     * @return Collection<int,SubscriptionWaiver>
     */
    public function waiverHistory(Farm $farm): Collection
    {
        return SubscriptionWaiver::with(['plan:id,slug,name', 'granter:id,name,email', 'revoker:id,name,email'])
            ->where('farm_id', $farm->id)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Move a farm onto a different tier without granting free time.
     */
    public function assignPlan(Farm $farm, SubscriptionPlan $plan, User $admin): FarmSubscription
    {
        $this->subscriptions->changePlan($farm, $plan, SubscriptionTransaction::SOURCE_ADMIN_OVERRIDE, $admin);

        return $this->entitlements->subscription($farm->fresh())->fresh('plan');
    }

    public function extendTrial(Farm $farm, int $days, User $admin): FarmSubscription
    {
        $subscription = $this->entitlements->subscription($farm);

        $base = $subscription->trial_ends_at?->isFuture() ? $subscription->trial_ends_at : now();
        $subscription->trial_ends_at = $base->copy()->addDays($days);
        $subscription->status = FarmSubscription::STATUS_TRIALING;
        $subscription->save();

        $this->subscriptions->recordTransaction(
            farm: $farm,
            plan: $subscription->plan,
            source: SubscriptionTransaction::SOURCE_ADMIN_OVERRIDE,
            event: 'trial.extended',
            payload: [
                'days' => $days,
                'trial_ends_at' => $subscription->trial_ends_at->toIso8601String(),
            ],
            actor: $admin,
        );

        return $subscription->fresh('plan');
    }

    /**
     * Subscription detail for the admin farm page.
     *
     * @return array<string,mixed>
     */
    public function detail(Farm $farm): array
    {
        return [
            'subscription' => $this->entitlements->summary($farm),
            'waivers' => $this->waiverHistory($farm),
            'plans' => SubscriptionPlan::orderBy('sort_order')->get(['slug', 'name', 'price_kobo', 'ai_enabled']),
        ];
    }

    /**
     * Platform-wide subscription KPIs for the admin dashboard.
     *
     * @return array<string,mixed>
     */
    public function kpis(): array
    {
        $byStatus = FarmSubscription::selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $byPlan = FarmSubscription::join('subscription_plans', 'subscription_plans.id', '=', 'farm_subscriptions.subscription_plan_id')
            ->whereIn('farm_subscriptions.status', [FarmSubscription::STATUS_ACTIVE, FarmSubscription::STATUS_WAIVED])
            ->selectRaw('subscription_plans.slug, subscription_plans.name, count(*) as total, sum(subscription_plans.price_kobo) as mrr_kobo')
            ->groupBy('subscription_plans.slug', 'subscription_plans.name')
            ->get();

        // Waived farms contribute no revenue, so they are excluded from MRR.
        $mrrKobo = FarmSubscription::join('subscription_plans', 'subscription_plans.id', '=', 'farm_subscriptions.subscription_plan_id')
            ->where('farm_subscriptions.status', FarmSubscription::STATUS_ACTIVE)
            ->sum('subscription_plans.price_kobo');

        return [
            'by_status' => $byStatus,
            'by_plan' => $byPlan,
            'mrr' => $mrrKobo / 100,
            'active' => (int) ($byStatus[FarmSubscription::STATUS_ACTIVE] ?? 0),
            'trialing' => (int) ($byStatus[FarmSubscription::STATUS_TRIALING] ?? 0),
            'waived' => (int) ($byStatus[FarmSubscription::STATUS_WAIVED] ?? 0),
            'read_only' => (int) ($byStatus[FarmSubscription::STATUS_READ_ONLY] ?? 0),
        ];
    }
}
