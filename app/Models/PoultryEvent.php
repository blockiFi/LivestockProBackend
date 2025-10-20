<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PoultryEvent extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'farm_id',
        'flock_id',
        'event_type',
        'table_name',
        'table_id',
        'event_date',
        'event',
        'performed_by'
    ];

    protected $casts = [
        'event_date' => 'date',
        'table_id' => 'integer'
    ];

    /**
     * Get the farm that owns the event.
     */
    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * Get the flock associated with the event.
     */
    public function flock(): BelongsTo
    {
        return $this->belongsTo(Flock::class);
    }

    /**
     * Get the user who performed the event.
     */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * Get the related model based on table_name and table_id
     */
    public function relatedModel()
    {
        if (!$this->table_name || !$this->table_id) {
            return null;
        }

        $modelClass = 'App\\Models\\' . ucfirst($this->table_name);
        if (class_exists($modelClass)) {
            return $modelClass::find($this->table_id);
        }

        return null;
    }
} 