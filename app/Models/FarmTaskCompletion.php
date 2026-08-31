<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarmTaskCompletion extends Model
{
    protected $fillable = [
        'instance_id',
        'completed_by',
        'completed_at',
        'notes',
        'worker_confirmed',
        'signature_text',
        'supervisor_approved',
        'approved_by',
        'approved_at',
        'approval_notes',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'approved_at' => 'datetime',
        'worker_confirmed' => 'boolean',
        'supervisor_approved' => 'boolean',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(FarmTaskInstance::class, 'instance_id');
    }

    public function completedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
