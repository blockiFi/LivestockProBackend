<?php

namespace App\Models;

use App\Notifications\DeliveryStatus;
use App\Notifications\NotificationChannel;
use App\Notifications\NotificationPriority;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Notification extends Model
{
    use HasFactory;

    protected $table = 'notifications_center';

    protected $fillable = [
        'farm_id',
        'user_id',
        'type',
        'category',
        'priority',
        'title',
        'body',
        'action_url',
        'action_label',
        'source_type',
        'source_id',
        'instance_id',
        'section',
        'payload',
        'dedupe_key',
        'status',
        'available_at',
        'read_at',
        'dismissed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'available_at' => 'datetime',
        'read_at' => 'datetime',
        'dismissed_at' => 'datetime',
    ];

    protected $appends = ['is_read'];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(FarmTaskInstance::class, 'instance_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo(null, 'source_type', 'source_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'notification_id');
    }

    public function getIsReadAttribute(): bool
    {
        return $this->read_at !== null;
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at')
            ->where(function (Builder $q) {
                $q->whereNull('available_at')->orWhere('available_at', '<=', now());
            });
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * When a farm is active, include farm-scoped rows plus user-level rows
     * (e.g. platform broadcasts) that have no farm_id.
     */
    public function scopeForFarmContext(Builder $query, ?int $farmId): Builder
    {
        if ($farmId === null) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($farmId) {
            $q->where('farm_id', $farmId)
                ->orWhereNull('farm_id');
        });
    }

    public function scopeProminent(Builder $query): Builder
    {
        return $query->whereIn('priority', [NotificationPriority::HIGH, NotificationPriority::CRITICAL]);
    }

    public function markRead(): bool
    {
        if ($this->read_at !== null) {
            return false;
        }

        $this->forceFill([
            'read_at' => now(),
            'status' => DeliveryStatus::READ,
        ])->save();

        $this->deliveries()
            ->where('channel', NotificationChannel::IN_APP)
            ->update(['status' => DeliveryStatus::READ, 'updated_at' => now()]);

        return true;
    }
}
