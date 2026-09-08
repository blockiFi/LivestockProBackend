<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlockChatMessage extends Model
{
    protected $fillable = [
        'session_id',
        'role',
        'content',
        'tool_call_id',
        'tool_name',
        'pending_actions',
        'metadata',
    ];

    protected $casts = [
        'pending_actions' => 'array',
        'metadata' => 'array',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(FlockChatSession::class, 'session_id');
    }
}
