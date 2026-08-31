<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ScheduleItemAdministrationMethod extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'schedule_item_id',
        'administration_method_id',
        'is_preferred',
    ];

    protected $casts = [
        'is_preferred' => 'boolean',
    ];

    public function scheduleItem(): BelongsTo
    {
        return $this->belongsTo(ScheduleItem::class);
    }

    public function administrationMethod(): BelongsTo
    {
        return $this->belongsTo(AdministrationMethod::class);
    }
}
