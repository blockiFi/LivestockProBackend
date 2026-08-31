<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FlockTransferLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'transfer_id',
        'from_house_id',
        'to_house_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(FlockTransfer::class, 'transfer_id');
    }

    public function fromHouse(): BelongsTo
    {
        return $this->belongsTo(PoultryHouse::class, 'from_house_id');
    }

    public function toHouse(): BelongsTo
    {
        return $this->belongsTo(PoultryHouse::class, 'to_house_id');
    }
}

