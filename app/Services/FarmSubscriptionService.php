<?php

namespace App\Services;

use App\Models\Farm;
use App\Models\FarmSubscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Writes to a farm's subscription. Read-side questions ("may this farm do X?")
 * belong to FarmEntitlementService.
 */
class FarmSubscriptionService
{
    public function __construct(private readonly FarmEntitlementService $entitlements)
    {
    }

    /**
     * Reasons a farm cannot move onto a smaller plan right now.
     *
     * @return array<int,string>
     */
    public function downgradeBlockers(Farm $farm, SubscriptionPlan $target): array
    {
        $blockers = [];
        $usage = $this->entitlements->usage($farm);

        if ($target->max_users !== null && $usage['user_seats_used'] > $target->max_users) {
            $blockers[] = sprintf(
                'The %s plan allows %d user but this farm has %d (including pending invitations). Remove members first.',
                $target->name,
                $target->max_users,
                $usage['user_seats_used']
            );
        }

        if ($target->max_active_flocks !== null && $usage['active_flocks'] > $target->max_active_flocks) {
            $blockers[] = sprintf(
                'The %s plan allows %d active batch but this farm has %d. End the extra batches first.',
                $target->name,
                $target->max_active_flocks,
                $usage['active_flocks']
            );
        }

        return $blockers;
    }

    /**
     * Switch plans without touching the billing window. Used by upgrades that
     * do not need a fresh Paystack charge and by admin overrides.
     */
    public function changePlan(Farm $farm, SubscriptionPlan $plan, string $source, ?User $actor = null): FarmSubscription
    {
        $subscription = $this->entitlements->subscription($farm);
        $previous = $subscription->plan;

        $subscription->subscription_plan_id = $plan->id;
        $subscription->save();

        $this->recordTransaction($farm, $plan, $source, 'plan.changed', [
            'from' => $previous?->slug,
            'to' => $plan->slug,
        ], $actor);

        return $subscription->fresh('plan');
    }

    /**
     * Mark a paid period as started. Called from the Paystack webhook and from
     * admin overrides that comp a farm without a waiver.
     */
    public function activate(
        FarmSubscription $subscription,
        SubscriptionPlan $plan,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): FarmSubscription {
        $subscription->subscription_plan_id = $plan->id;
        $subscription->current_period_start = $periodStart;
        $subscription->current_period_end = $periodEnd;
        $subscription->grace_ends_at = null;
        $subscription->cancelled_at = null;
        $subscription->ends_at = null;

        // An active waiver keeps precedence: the farm already has free access.
        if (! $subscription->isWaived()) {
            $subscription->status = FarmSubscription::STATUS_ACTIVE;
        }

        $subscription->save();

        return $subscription;
    }

    public function markPastDue(FarmSubscription $subscription): FarmSubscription
    {
        if ($subscription->isWaived()) {
            return $subscription;
        }

        $graceDays = (int) config('subscription.grace_days', 3);

        $subscription->status = FarmSubscription::STATUS_PAST_DUE;
        $subscription->grace_ends_at = ($subscription->current_period_end ?? now())->copy()->addDays($graceDays);
        $subscription->save();

        return $subscription;
    }

    /**
     * Cancellation runs to the end of the period the farm already paid for.
     */
    public function cancel(Farm $farm, ?User $actor = null): FarmSubscription
    {
        $subscription = $this->entitlements->subscription($farm);

        $subscription->status = FarmSubscription::STATUS_CANCELLED;
        $subscription->cancelled_at = now();
        $subscription->ends_at = $subscription->current_period_end ?? now();
        $subscription->save();

        $this->recordTransaction($farm, $subscription->plan, SubscriptionTransaction::SOURCE_CHECKOUT, 'subscription.cancelled', [
            'ends_at' => $subscription->ends_at?->toIso8601String(),
        ], $actor);

        return $subscription;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function recordTransaction(
        Farm $farm,
        ?SubscriptionPlan $plan,
        string $source,
        ?string $event = null,
        array $payload = [],
        ?User $actor = null,
        ?int $amountKobo = null,
        ?string $reference = null,
        ?string $status = null,
    ): SubscriptionTransaction {
        return SubscriptionTransaction::create([
            'farm_id' => $farm->id,
            'subscription_plan_id' => $plan?->id,
            'source' => $source,
            'event' => $event,
            'amount_kobo' => $amountKobo,
            'currency' => $plan?->currency,
            'status' => $status,
            'reference' => $reference,
            'payload' => $payload ?: null,
            'performed_by' => $actor?->id,
        ]);
    }
}
