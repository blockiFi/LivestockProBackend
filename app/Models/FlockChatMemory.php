<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlockChatMemory extends Model
{
    public const KIND_PREFERENCE = 'preference';

    public const KIND_FACT = 'fact';

    public const KIND_ACTION_OUTCOME = 'action_outcome';

    protected $fillable = [
        'farm_id',
        'flock_id',
        'user_id',
        'kind',
        'content',
        'source_session_id',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function flock(): BelongsTo
    {
        return $this->belongsTo(Flock::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceSession(): BelongsTo
    {
        return $this->belongsTo(FlockChatSession::class, 'source_session_id');
    }
}
