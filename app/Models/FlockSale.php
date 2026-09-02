<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FlockSale extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'farm_id',
        'customer_id',
        'flock_id',
        'quantity',
        'unit_price',
        'total_amount',
        'date',
        'customer_name',
        'customer_phone',
        'notes',
        'daily_record_id',
        'culls_applied',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'date' => 'date',
        'quantity' => 'integer',
        'culls_applied' => 'integer',
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

    public function dailyRecord(): BelongsTo
    {
        return $this->belongsTo(FlockDailyRecord::class, 'daily_record_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
