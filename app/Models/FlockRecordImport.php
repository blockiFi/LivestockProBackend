<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlockRecordImport extends Model
{
    public const METHOD_AI = 'ai';
    public const METHOD_FILE = 'file';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'farm_id',
        'flock_id',
        'created_by',
        'source_method',
        'source_type',
        'source_path',
        'original_filename',
        'status',
        'llm_provider',
        'llm_model',
        'llm_raw_response',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function flock(): BelongsTo
    {
        return $this->belongsTo(Flock::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FlockRecordImportItem::class)->orderBy('row_index');
    }
}
