<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FarmSubscription extends Model
{
    public const STATUS_TRIALING = 'trialing';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_WAIVED = 'waived';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_GRACE = 'grace';
    public const STATUS_READ_ONLY = 'read_only';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Statuses that grant the farm full use of its plan entitlements.
     */
    public const ENTITLED_STATUSES = [
        self::STATUS_TRIALING,
        self::STATUS_ACTIVE,
        self::STATUS_WAIVED,
        self::STATUS_GRACE,
    ];

    protected $fillable = [
        'farm_id',
        'subscription_plan_id',
        'status',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
        'grace_ends_at',
        'waived_until',
        'paystack_customer_code',
        'paystack_subscription_code',
        'paystack_email_token',
        'cancelled_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'grace_ends_at' => 'datetime',
            'waived_until' => 'datetime',
            'cancelled_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function waivers(): HasMany
    {
        return $this->hasMany(SubscriptionWaiver::class);
    }

    public function activeWaiver(): ?SubscriptionWaiver
    {
        return $this->waivers()
            ->where('status', SubscriptionWaiver::STATUS_ACTIVE)
            ->where('ends_at', '>', now())
            ->latest('ends_at')
            ->first();
    }

    public function isWaived(): bool
    {
        return $this->waived_until !== null && $this->waived_until->isFuture();
    }

    public function isEntitled(): bool
    {
        return in_array($this->status, self::ENTITLED_STATUSES, true);
    }

    public function isReadOnly(): bool
    {
        return ! $this->isEntitled();
    }

    /**
     * Paystack keeps billing this farm even while an admin waiver is running,
     * so the code is only meaningful once the waiver has lapsed.
     */
    public function hasPaystackSubscription(): bool
    {
        return $this->paystack_subscription_code !== null;
    }
}
