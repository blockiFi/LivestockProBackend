<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeedingBatchScheduleItem extends Model
{
    protected $fillable = [
        'feeding_batch_schedule_id',
        'feeding_schedule_item_id',
        'actual_feeding_time',
        'actual_quantity',
        'status',
    ];

    protected $casts = [
        'actual_feeding_time' => 'array',
    ];

    public function batchSchedule()
    {
        return $this->belongsTo(FeedingBatchSchedule::class, 'feeding_batch_schedule_id');
    }

    public function scheduleItem()
    {
        return $this->belongsTo(FeedingScheduleItem::class, 'feeding_schedule_item_id');
    }
} 