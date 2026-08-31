<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionWaiver extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';

    public const MAX_MONTHS = 24;

    protected $fillable = [
        'farm_id',
        'farm_subscription_id',
        'subscription_plan_id',
        'months',
        'starts_at',
        'ends_at',
        'reason',
        'granted_by',
        'revoked_at',
        'revoked_by',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'months' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(FarmSubscription::class, 'farm_subscription_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    /**
     * Named "granter" rather than "grantedBy" so serialization does not clash
     * with the granted_by column.
     */
    public function granter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
