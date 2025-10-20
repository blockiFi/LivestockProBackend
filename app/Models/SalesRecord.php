<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesRecord extends Model
{
    protected $fillable = [
        'date',
        'quantity',
        'unit_price',
        'total_amount',
        'payment_status',
        'payment_method',
        'notes',
        'farm_id',
        'customer_id',
        'created_by'
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
} 