<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeedingScheduleItem extends Model
{
    protected $fillable = [
        'feeding_schedule_id',
        'feed_type_id',
        'feeding_times',
    ];

    protected $casts = [
        'feeding_times' => 'array',
    ];

    public function schedule()
    {
        return $this->belongsTo(FeedingSchedule::class, 'feeding_schedule_id');
    }

    public function feedType()
    {
        return $this->belongsTo(PoultryFeedType::class, 'feed_type_id');
    }

    public function batchScheduleItems()
    {
        return $this->hasMany(FeedingBatchScheduleItem::class);
    }
} 