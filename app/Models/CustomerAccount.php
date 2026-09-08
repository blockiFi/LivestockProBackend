<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerAccount extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_FROZEN = 'frozen';

    protected $fillable = [
        'farm_id',
        'customer_id',
        'balance',
        'currency',
        'status',
        'low_balance_threshold',
        'total_credited',
        'total_debited',
        'last_top_up_at',
        'last_payment_at',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'low_balance_threshold' => 'decimal:2',
        'total_credited' => 'decimal:2',
        'total_debited' => 'decimal:2',
        'last_top_up_at' => 'datetime',
        'last_payment_at' => 'datetime',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CustomerAccountTransaction::class);
    }

    public function isFrozen(): bool
    {
        return $this->status === self::STATUS_FROZEN;
    }

    public function isLowBalance(): bool
    {
        if ($this->low_balance_threshold === null) {
            return false;
        }

        return (float) $this->balance < (float) $this->low_balance_threshold;
    }
}
