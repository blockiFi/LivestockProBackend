<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentDocument extends Model
{
    protected $fillable = [
        'farm_id',
        'equipment_id',
        'document_type',
        'name',
        'storage_path',
        'mime_type',
        'size_bytes',
        'expires_at',
        'uploaded_by',
    ];

    protected $casts = [
        'expires_at' => 'date',
        'size_bytes' => 'integer',
    ];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
