<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatchScheduleItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'batch_schedule_id',
        'schedule_item_id',
        'status',
        'scheduled_date',
        'actual_date',
        'administered_by',
        'poultry_vaccine_product_id',
        'vaccine_product_batch_id',
        'poultry_medication_id',
        'dosage',
        'quantity',
        'cost',
        'notes',
        'administration_method_id',
    ];

    public function batchSchedule(): BelongsTo
    {
        return $this->belongsTo(BatchSchedule::class);
    }

    public function scheduleItem(): BelongsTo
    {
        return $this->belongsTo(ScheduleItem::class);
    }
    
} 