<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One sale drawn from one or more batches. The lines are SalesRecord rows
 * (one per flock), which carry the per-batch quantity, price and revenue.
 */
class SalesOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'farm_id',
        'type',
        'date',
        'customer_id',
        'customer_name',
        'customer_phone',
        'total_amount',
        'amount_paid',
        'payment_method',
        'payment_status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesRecord::class);
    }
}
