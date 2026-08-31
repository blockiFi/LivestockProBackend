<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentRetirement extends Model
{
    protected $fillable = [
        'farm_id',
        'equipment_id',
        'disposal_method',
        'disposal_date',
        'reason',
        'final_condition',
        'sale_price',
        'buyer_recipient',
        'authorized_by',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'disposal_date' => 'date',
        'sale_price' => 'decimal:2',
    ];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function authorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }
}
