<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarmShare extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'kind',
        'source_farm_id',
        'source_item_id',
        'item_name',
        'item_subtype',
        'item_count',
        'target_farm_id',
        'shared_by',
        'message',
        'status',
        'responded_by',
        'responded_at',
        'accepted_item_id',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
        'item_count' => 'integer',
    ];

    public function sourceFarm(): BelongsTo
    {
        return $this->belongsTo(Farm::class, 'source_farm_id');
    }

    public function targetFarm(): BelongsTo
    {
        return $this->belongsTo(Farm::class, 'target_farm_id');
    }

    public function sharedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by');
    }

    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }
}
