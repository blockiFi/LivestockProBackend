<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Schedule extends Model
{
    protected $fillable = [
        'name',
        'description',
        'status',
        'type',
        'farm_id',
        'created_by',
        'schedule_type',
        'poultry_type_id',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ScheduleItem::class);
    }

    public function batchSchedules(): HasMany
    {
        return $this->hasMany(BatchSchedule::class);
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poultryType(): BelongsTo
    {
        return $this->belongsTo(PoultryType::class, 'poultry_type_id');
    }
}