<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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
        ];
    }

    protected $appends = ['price'];

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

    public function allowsUnlimitedUsers(): bool
    {
        return $this->max_users === null;
    }

    public function allowsUnlimitedFlocks(): bool
    {
        return $this->max_active_flocks === null;
    }
}
