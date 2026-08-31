<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleImportItem extends Model
{
    protected $fillable = [
        'schedule_import_id',
        'kind',
        'age_days',
        'feeding_day',
        'start_day',
        'end_day',
        'name',
        'dose',
        'withdrawal_period_days',
        'storage_instructions',
        'description',
        'feed_type_id',
        'quantity',
        'feeding_times',
        'confidence',
        'notes',
    ];

    protected $casts = [
        'feeding_times' => 'array',
        'confidence' => 'float',
        'quantity' => 'float',
        'feeding_day' => 'integer',
        'start_day' => 'integer',
        'end_day' => 'integer',
    ];

    public function scheduleImport(): BelongsTo
    {
        return $this->belongsTo(ScheduleImport::class);
    }

    public function feedType(): BelongsTo
    {
        return $this->belongsTo(PoultryFeedType::class, 'feed_type_id');
    }
}

