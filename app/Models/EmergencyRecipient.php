<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmergencyRecipient extends Model
{
    use HasFactory;

    protected $fillable = [
        'emergency_id',
        'user_id',
        'notified_at',
        'acknowledged_at',
        'alert_count',
        'last_alerted_at',
    ];

    protected function casts(): array
    {
        return [
            'notified_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'last_alerted_at' => 'datetime',
            'alert_count' => 'integer',
        ];
    }

    public function emergency(): BelongsTo
    {
        return $this->belongsTo(Emergency::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('acknowledged_at');
    }

    public function scopeAcknowledged(Builder $query): Builder
    {
        return $query->whereNotNull('acknowledged_at');
    }

    public function hasAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }

    /** Wall-clock seconds between the emergency being sent and this person acknowledging. */
    public function responseSeconds(): ?int
    {
        if (! $this->acknowledged_at) {
            return null;
        }

        return $this->emergency->created_at->diffInSeconds($this->acknowledged_at);
    }
}
