<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\BillingCycle;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    public const BASIC = 'basic';
    public const STANDARD = 'standard';
    public const PREMIUM = 'premium';

    protected $fillable = [
        'slug',
        'name',
        'description',
        'price_kobo',
        'currency',
        'max_users',
        'max_active_flocks',
        'ai_enabled',
        'paystack_plan_code',
        'paystack_cycle_plans',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price_kobo' => 'integer',
            'max_users' => 'integer',
            'max_active_flocks' => 'integer',
            'ai_enabled' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'paystack_cycle_plans' => 'array',
        ];
    }

    protected $appends = ['price'];

    protected $hidden = ['paystack_cycle_plans'];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(FarmSubscription::class);
    }

    /**
     * Price in major units (Naira) for display.
     */
    public function getPriceAttribute(): float
    {
        return $this->price_kobo / 100;
    }

    /**
     * What one payment on this plan costs for the given cycle, in kobo.
     */
    public function priceKoboFor(string $cycle): int
    {
        return BillingCycle::priceKobo($this, $cycle);
    }

    /**
     * The Paystack plan code that bills this plan on the given cycle, if one
     * exists for the current price. Monthly uses the plan's own code.
     */
    public function paystackPlanCodeFor(string $cycle): ?string
    {
        if ($cycle === BillingCycle::MONTHLY) {
            return $this->paystack_plan_code;
        }

        $entry = ($this->paystack_cycle_plans ?? [])[$cycle] ?? null;

        // A price change makes the stored Paystack plan stale.
        if (! $entry || (int) ($entry['amount'] ?? 0) !== $this->priceKoboFor($cycle)) {
            return null;
        }

        return $entry['code'] ?? null;
    }

    public function rememberPaystackPlanCode(string $cycle, string $code): void
    {
        $plans = $this->paystack_cycle_plans ?? [];
        $plans[$cycle] = ['code' => $code, 'amount' => $this->priceKoboFor($cycle)];
        $this->paystack_cycle_plans = $plans;
        $this->save();
    }

    /**
     * Which plan and cycle a Paystack plan code belongs to, including codes
     * from before a price change so renewals still resolve.
     *
     * @return array{0:SubscriptionPlan,1:string}|null
     */
    public static function findByPaystackCode(string $code): ?array
    {
        foreach (static::all() as $plan) {
            if ($plan->paystack_plan_code === $code) {
                return [$plan, BillingCycle::MONTHLY];
            }

            foreach (($plan->paystack_cycle_plans ?? []) as $cycle => $entry) {
                if (($entry['code'] ?? null) === $code) {
                    return [$plan, $cycle];
                }
            }
        }

        return null;
    }

    public function allowsUnlimitedUsers(): bool
    {
        return $this->max_users === null;
    }

    public function allowsUnlimitedFlocks(): bool
    {
        return $this->max_active_flocks === null;
    }
}
