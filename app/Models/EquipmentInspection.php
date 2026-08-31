<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentInspection extends Model
{
    protected $fillable = [
        'farm_id',
        'equipment_id',
        'inspection_date',
        'inspector_user_id',
        'condition',
        'findings',
        'problems_identified',
        'recommended_action',
        'notes',
        'next_inspection_date',
        'created_by',
    ];

    protected $casts = [
        'inspection_date' => 'date',
        'next_inspection_date' => 'date',
    ];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_user_id');
    }
}
