<?php

namespace App\Services;

use App\Models\Farm;
use App\Models\FarmSubscription;
use App\Models\FarmUserInvitation;
use App\Models\Flock;
use App\Models\SubscriptionPlan;

/**
 * Resolves what a farm is allowed to do based on its subscription.
 *
 * Entitlements are deliberately separate from Spatie permissions: a user may
 * have "manage users" on the farm yet still be blocked because the farm's plan
 * has run out of seats.
 */
class FarmEntitlementService
{
    public const ACTION_WRITE = 'write';
    public const ACTION_ADD_USER = 'add_user';
    public const ACTION_CREATE_ACTIVE_FLOCK = 'create_active_flock';
    public const ACTION_USE_AI = 'use_ai';

    /**
     * Fetch the farm's subscription, creating a trial for farms that predate
     * the billing system.
     */
    public function subscription(Farm $farm): FarmSubscription
    {
        $subscription = $farm->relationLoaded('subscription')
            ? $farm->subscription
            : $farm->subscription()->first();

        if ($subscription) {
            return $subscription;
        }

        $subscription = FarmSubscription::create([
            'farm_id' => $farm->id,
            'subscription_plan_id' => $this->trialPlan()->id,
            'status' => FarmSubscription::STATUS_TRIALING,
            'trial_ends_at' => now()->addDays((int) config('subscription.trial_days', 14)),
        ]);

        $farm->setRelation('subscription', $subscription);

        return $subscription;
    }

    public function plan(Farm $farm): SubscriptionPlan
    {
        $subscription = $this->subscription($farm);

        return $subscription->plan ?? $subscription->plan()->firstOrFail();
    }

    /**
     * The status a farm is really in right now, derived from its dates rather
     * than the stored column so enforcement stays correct between runs of the
     * daily reconciliation command.
     */
    public function status(Farm $farm): string
    {
        return $this->effectiveStatus($this->subscription($farm));
    }

    public function effectiveStatus(FarmSubscription $subscription): string
    {
        if ($subscription->isWaived()) {
            return FarmSubscription::STATUS_WAIVED;
        }

        $graceDays = (int) config('subscription.grace_days', 3);

        return match ($subscription->status) {
            FarmSubscription::STATUS_TRIALING => $subscription->trial_ends_at?->isFuture()
                ? FarmSubscription::STATUS_TRIALING
                : FarmSubscription::STATUS_READ_ONLY,

            FarmSubscription::STATUS_WAIVED => $this->statusAfterWaiver($subscription, $graceDays),

            FarmSubscription::STATUS_ACTIVE => $this->paidStatus($subscription, $graceDays),

            FarmSubscription::STATUS_PAST_DUE, FarmSubscription::STATUS_GRACE => $this->graceStatus($subscription, $graceDays),

            // A cancellation runs to the end of the period that was paid for.
            FarmSubscription::STATUS_CANCELLED => $subscription->ends_at?->isFuture()
                ? FarmSubscription::STATUS_ACTIVE
                : FarmSubscription::STATUS_READ_ONLY,

            default => FarmSubscription::STATUS_READ_ONLY,
        };
    }

    /**
     * After a complimentary period ends, fall back to the paid window if one
     * is still valid. Otherwise the farm is locked until they subscribe.
     */
    private function statusAfterWaiver(FarmSubscription $subscription, int $graceDays): string
    {
        if ($subscription->current_period_end?->isFuture()) {
            return FarmSubscription::STATUS_ACTIVE;
        }

        if ($subscription->current_period_end) {
            return $this->paidStatus($subscription, $graceDays);
        }

        return FarmSubscription::STATUS_READ_ONLY;
    }

    private function paidStatus(FarmSubscription $subscription, int $graceDays): string
    {
        if ($subscription->current_period_end === null) {
            // Marked paid without a period (e.g. admin override) — trust it.
            return FarmSubscription::STATUS_ACTIVE;
        }

        if ($subscription->current_period_end->isFuture()) {
            return FarmSubscription::STATUS_ACTIVE;
        }

        return $subscription->current_period_end->copy()->addDays($graceDays)->isFuture()
            ? FarmSubscription::STATUS_GRACE
            : FarmSubscription::STATUS_READ_ONLY;
    }

    private function graceStatus(FarmSubscription $subscription, int $graceDays): string
    {
        $graceEnd = $subscription->grace_ends_at
            ?? $subscription->current_period_end?->copy()->addDays($graceDays);

        return $graceEnd?->isFuture()
            ? FarmSubscription::STATUS_GRACE
            : FarmSubscription::STATUS_READ_ONLY;
    }

    public function isWaived(Farm $farm): bool
    {
        return $this->subscription($farm)->isWaived();
    }

    /**
     * Whether the farm's plan entitlements currently apply at all.
     */
    public function canWrite(Farm $farm): bool
    {
        return in_array($this->status($farm), FarmSubscription::ENTITLED_STATUSES, true);
    }

    public function canUseAi(Farm $farm): bool
    {
        return $this->canWrite($farm) && $this->plan($farm)->ai_enabled;
    }

    public function canAddUser(Farm $farm): bool
    {
        if (! $this->canWrite($farm)) {
            return false;
        }

        $max = $this->plan($farm)->max_users;

        return $max === null || $this->userSeatsUsed($farm) < $max;
    }

    public function canCreateActiveFlock(Farm $farm): bool
    {
        if (! $this->canWrite($farm)) {
            return false;
        }

        $max = $this->plan($farm)->max_active_flocks;

        return $max === null || $this->activeFlockCount($farm) < $max;
    }

    /**
     * Pending invitations count against the cap so a Basic farm cannot queue up
     * seats it is not paying for.
     */
    public function userSeatsUsed(Farm $farm): int
    {
        return $farm->users()->count() + $this->pendingInvitationCount($farm);
    }

    public function pendingInvitationCount(Farm $farm): int
    {
        return FarmUserInvitation::where('farm_id', $farm->id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->count();
    }

    public function activeFlockCount(Farm $farm): int
    {
        return Flock::where('farm_id', $farm->id)->where('status', 'active')->count();
    }

    /**
     * Snapshot for the billing UI and API payloads.
     *
     * @return array<string,mixed>
     */
    public function usage(Farm $farm): array
    {
        $plan = $this->plan($farm);

        return [
            'users' => $farm->users()->count(),
            'pending_invitations' => $this->pendingInvitationCount($farm),
            'user_seats_used' => $this->userSeatsUsed($farm),
            'active_flocks' => $this->activeFlockCount($farm),
            'max_users' => $plan->max_users,
            'max_active_flocks' => $plan->max_active_flocks,
        ];
    }

    /**
     * Full entitlement payload the frontend uses to gate UI without extra
     * round-trips.
     *
     * @return array<string,mixed>
     */
    public function summary(Farm $farm): array
    {
        $subscription = $this->subscription($farm);
        $plan = $this->plan($farm);
        $status = $this->effectiveStatus($subscription);
        $waiver = $subscription->activeWaiver();

        return [
            'plan' => [
                'slug' => $plan->slug,
                'name' => $plan->name,
                'description' => $plan->description,
                'price' => $plan->price,
                'price_kobo' => $plan->price_kobo,
                'currency' => $plan->currency,
                'max_users' => $plan->max_users,
                'max_active_flocks' => $plan->max_active_flocks,
                'ai_enabled' => $plan->ai_enabled,
            ],
            'status' => $status,
            'is_read_only' => ! in_array($status, FarmSubscription::ENTITLED_STATUSES, true),
            'ai_enabled' => $this->canUseAi($farm),
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            'waived_until' => $subscription->waived_until?->toIso8601String(),
            'cancelled_at' => $subscription->cancelled_at?->toIso8601String(),
            'ends_at' => $subscription->ends_at?->toIso8601String(),
            'waiver' => $waiver ? [
                'plan_name' => $waiver->plan?->name,
                'months' => $waiver->months,
                'starts_at' => $waiver->starts_at->toIso8601String(),
                'ends_at' => $waiver->ends_at->toIso8601String(),
                'reason' => $waiver->reason,
            ] : null,
            'usage' => $this->usage($farm),
            'billing_url' => config('subscription.billing_url'),
        ];
    }

    /**
     * Why an action is not allowed, or null when it is.
     *
     * @return array{code:string,message:string,status:int}|null
     */
    public function denialFor(Farm $farm, string $action): ?array
    {
        if ($action === self::ACTION_USE_AI && ! $this->plan($farm)->ai_enabled) {
            return [
                'code' => 'ai_not_included',
                'message' => 'AI features require the Premium plan. Upgrade this farm to unlock AI.',
                'status' => 403,
            ];
        }

        if (! $this->canWrite($farm)) {
            return [
                'code' => 'subscription_read_only',
                'message' => $this->readOnlyMessage($farm),
                'status' => 402,
            ];
        }

        return match ($action) {
            self::ACTION_ADD_USER => $this->canAddUser($farm) ? null : [
                'code' => 'plan_user_limit_reached',
                'message' => sprintf(
                    'The %s plan allows %d user. Upgrade to Standard for unlimited team members.',
                    $this->plan($farm)->name,
                    (int) $this->plan($farm)->max_users
                ),
                'status' => 402,
            ],

            self::ACTION_CREATE_ACTIVE_FLOCK => $this->canCreateActiveFlock($farm) ? null : [
                'code' => 'plan_batch_limit_reached',
                'message' => sprintf(
                    'The %s plan allows %d active batch at a time. End the current batch or upgrade to Standard for unlimited batches.',
                    $this->plan($farm)->name,
                    (int) $this->plan($farm)->max_active_flocks
                ),
                'status' => 402,
            ],

            default => null,
        };
    }

    private function readOnlyMessage(Farm $farm): string
    {
        $subscription = $this->subscription($farm);

        if ($subscription->status === FarmSubscription::STATUS_TRIALING) {
            return 'Your free trial has ended. Choose a plan to continue adding records.';
        }

        return 'This farm\'s subscription has lapsed. Renew to continue adding records.';
    }

    private function trialPlan(): SubscriptionPlan
    {
        $slug = config('subscription.trial_plan', SubscriptionPlan::BASIC);

        return SubscriptionPlan::where('slug', $slug)->firstOrFail();
    }
}
