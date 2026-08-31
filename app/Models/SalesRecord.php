<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesRecord extends Model
{
    use SoftDeletes;

    public const TYPES = ['egg', 'meat', 'manure'];

    public const PAYMENT_STATUSES = ['pending', 'paid', 'partial'];

    protected $fillable = [
        'farm_id',
        'flock_id',
        'type',
        'quantity',
        'unit_price',
        'total_amount',
        'date',
        'customer_id',
        'customer_name',
        'customer_phone',
        'payment_method',
        'payment_status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function flock(): BelongsTo
    {
        return $this->belongsTo(Flock::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
