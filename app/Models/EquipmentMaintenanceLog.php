<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentMaintenanceLog extends Model
{
    protected $fillable = [
        'farm_id',
        'equipment_id',
        'maintenance_type',
        'title',
        'description',
        'performed_at',
        'next_due_at',
        'service_provider',
        'technician',
        'parts_replaced',
        'labour_cost',
        'parts_cost',
        'total_cost',
        'notes',
        'performed_by_user_id',
        'created_by',
    ];

    protected $casts = [
        'performed_at' => 'date',
        'next_due_at' => 'date',
        'labour_cost' => 'decimal:2',
        'parts_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
    ];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }
}
