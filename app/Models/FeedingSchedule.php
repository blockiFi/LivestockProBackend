<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeedingSchedule extends Model
{
    protected $fillable = [
        'title',
        'description',
        'start_date',
        'end_date',
        'farm_id',
        'type',
    ];

    public function items()
    {
        return $this->hasMany(FeedingScheduleItem::class);
    }

    public function batchSchedules()
    {
        return $this->hasMany(FeedingBatchSchedule::class);
    }
}