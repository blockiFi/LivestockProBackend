<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionTransaction extends Model
{
    public const SOURCE_CHECKOUT = 'checkout';
    public const SOURCE_PAYSTACK_WEBHOOK = 'paystack_webhook';
    public const SOURCE_ADMIN_WAIVER = 'admin_waiver';
    public const SOURCE_ADMIN_OVERRIDE = 'admin_override';

    protected $fillable = [
        'farm_id',
        'subscription_plan_id',
        'source',
        'event',
        'amount_kobo',
        'currency',
        'status',
        'reference',
        'payload',
        'performed_by',
    ];

    protected function casts(): array
    {
        return [
            'amount_kobo' => 'integer',
            'payload' => 'array',
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

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
