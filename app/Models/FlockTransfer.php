<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FlockTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'farm_id',
        'flock_id',
        'transfer_date',
        'note',
        'created_by',
    ];

    protected $casts = [
        'transfer_date' => 'date',
    ];

    public function flock(): BelongsTo
    {
        return $this->belongsTo(Flock::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FlockTransferLine::class, 'transfer_id');
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

