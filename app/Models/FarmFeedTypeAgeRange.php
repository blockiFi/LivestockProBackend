<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarmFeedTypeAgeRange extends Model
{
    protected $fillable = [
        'farm_id',
        'poultry_feed_type_id',
        'start_age',
        'end_age',
    ];

    protected $casts = [
        'start_age' => 'integer',
        'end_age' => 'integer',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function feedType(): BelongsTo
    {
        return $this->belongsTo(PoultryFeedType::class, 'poultry_feed_type_id');
    }
}
