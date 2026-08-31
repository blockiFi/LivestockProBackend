<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentTransfer extends Model
{
    protected $fillable = [
        'farm_id',
        'equipment_id',
        'previous_location',
        'new_location',
        'previous_section',
        'new_section',
        'previous_department',
        'new_department',
        'previous_assignee_id',
        'new_assignee_id',
        'previous_house_id',
        'new_house_id',
        'transferred_at',
        'transferred_by',
        'reason',
        'notes',
    ];

    protected $casts = [
        'transferred_at' => 'datetime',
    ];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function previousAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'previous_assignee_id');
    }

    public function newAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'new_assignee_id');
    }

    public function transferredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }
}
