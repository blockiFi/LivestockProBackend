<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FeedingScheduleItem extends Model
{
    protected $fillable = [
        'feeding_schedule_id',
        'feed_type_id',
        'feeding_times',
        'quantity',
        'feeding_day',
        'start_day',
        'end_day',
    ];

    protected $casts = [
        'feeding_times' => 'json',
        'quantity' => 'decimal:2',
        'feeding_day' => 'integer',
        'start_day' => 'integer',
        'end_day' => 'integer',
    ];

    protected $appends = [
        'is_open_ended',
        'day_count',
    ];

    protected static function booted(): void
    {
        static::saving(function (FeedingScheduleItem $item) {
            // Legacy clients send feeding_day only — treat as a 1-day range.
            if ($item->start_day === null && $item->feeding_day !== null) {
                $item->start_day = (int) $item->feeding_day;
                if (!$item->isDirty('end_day') && $item->end_day === null) {
                    $item->end_day = (int) $item->feeding_day;
                }
            }

            // Keep feeding_day as a legacy mirror of start_day.
            if ($item->start_day !== null) {
                $item->feeding_day = (int) $item->start_day;
            }
        });
    }

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

    /**
     * Ranges that cover the given placement day.
     */
    public function scopeCoveringDay(Builder $query, int $day): Builder
    {
        return $query
            ->where('start_day', '<=', $day)
            ->where(function (Builder $q) use ($day) {
                $q->whereNull('end_day')
                    ->orWhere('end_day', '>=', $day);
            });
    }

    public function getIsOpenEndedAttribute(): bool
    {
        return $this->end_day === null;
    }

    /**
     * Number of days covered, or null when open-ended.
     */
    public function getDayCountAttribute(): ?int
    {
        if ($this->start_day === null || $this->end_day === null) {
            return null;
        }

        return max(0, (int) $this->end_day - (int) $this->start_day + 1);
    }

    public function coversDay(int $day): bool
    {
        if ($this->start_day === null || $day < (int) $this->start_day) {
            return false;
        }

        if ($this->end_day === null) {
            return true;
        }

        return $day <= (int) $this->end_day;
    }
}
