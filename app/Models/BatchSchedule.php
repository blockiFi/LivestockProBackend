<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BatchSchedule extends Model
{
    protected $fillable = [
        'farm_id',
        'flock_id',
        'schedule_id'
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BatchScheduleItem::class);
    }
} 