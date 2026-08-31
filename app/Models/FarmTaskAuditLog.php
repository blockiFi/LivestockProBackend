<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarmTaskAuditLog extends Model
{
    protected $fillable = [
        'farm_id',
        'instance_id',
        'schedule_id',
        'actor_id',
        'action',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(FarmTaskInstance::class, 'instance_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(FarmTaskSchedule::class, 'schedule_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
