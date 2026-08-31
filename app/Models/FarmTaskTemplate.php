<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FarmTaskTemplate extends Model
{
    protected $fillable = [
        'farm_id',
        'title',
        'description',
        'section',
        'priority',
        'instructions',
        'notes',
        'animal_group',
        'medication_name',
        'dosage_instructions',
        'require_completion_confirmation',
        'require_supervisor_approval',
        'require_signature',
        'created_by',
    ];

    protected $casts = [
        'require_completion_confirmation' => 'boolean',
        'require_supervisor_approval' => 'boolean',
        'require_signature' => 'boolean',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(FarmTaskSchedule::class, 'template_id');
    }
}
