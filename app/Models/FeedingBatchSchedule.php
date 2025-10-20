<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeedingBatchSchedule extends Model
{
    protected $fillable = [
        'flock_id',
        'feeding_schedule_id',
        'status',
    ];

    public function flock()
    {
        return $this->belongsTo(Flock::class);
    }

    public function schedule()
    {
        return $this->belongsTo(FeedingSchedule::class, 'feeding_schedule_id');
    }

    public function items()
    {
        return $this->hasMany(FeedingBatchScheduleItem::class);
    }
} 