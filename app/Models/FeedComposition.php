<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FeedComposition extends Model
{
    use HasFactory;

    protected $fillable = [
        'poultry_feed_product_id',
        'feed_component_id',
        'percentage',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(PoultryFeedProduct::class, 'poultry_feed_product_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(FeedComponent::class, 'feed_component_id');
    }
}

