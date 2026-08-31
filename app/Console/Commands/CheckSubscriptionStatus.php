<?php

namespace App\Console\Commands;

use App\Models\FarmSubscription;
use App\Models\SubscriptionWaiver;
use App\Services\FarmEntitlementService;
use Illuminate\Console\Command;

class CheckSubscriptionStatus extends Command
{
    protected $signature = 'subscriptions:check-status';

    protected $description = 'Move farm subscriptions through trial, grace, waiver and read-only transitions';

    public function __construct(private readonly FarmEntitlementService $entitlements)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $expiredWaivers = $this->expireWaivers();
        $transitions = 0;

        FarmSubscription::with('plan')->chunkById(200, function ($subscriptions) use (&$transitions) {
            foreach ($subscriptions as $subscription) {
                $effective = $this->entitlements->effectiveStatus($subscription);

                if ($effective === $subscription->status) {
                    continue;
                }

                $subscription->status = $effective;

                // Stamp the grace window the first time we notice a lapse so the
                // deadline does not drift on later runs.
                if ($effective === FarmSubscription::STATUS_GRACE && $subscription->grace_ends_at === null) {
                    $graceDays = (int) config('subscription.grace_days', 3);
                    $subscription->grace_ends_at = ($subscription->current_period_end ?? now())
                        ->copy()
                        ->addDays($graceDays);
                }

                if ($effective === FarmSubscription::STATUS_READ_ONLY) {
                    $subscription->grace_ends_at = null;
                }

                $subscription->save();
                $transitions++;
            }
        });

        $this->info("Expired {$expiredWaivers} waiver(s) and updated {$transitions} subscription status(es).");

        return self::SUCCESS;
    }

    private function expireWaivers(): int
    {
        return SubscriptionWaiver::where('status', SubscriptionWaiver::STATUS_ACTIVE)
            ->where('ends_at', '<=', now())
            ->update(['status' => SubscriptionWaiver::STATUS_EXPIRED]);
    }
}
