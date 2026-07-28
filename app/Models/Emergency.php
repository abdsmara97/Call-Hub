<?php

namespace App\Models;

use App\Enums\EmergencyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Emergency extends Model
{
    use HasFactory;

    protected $fillable = [
        'sender_id',
        'body',
        'scope',
        'room_id',
        'company_id',
        'administration_id',
        'escalation_interval_minutes',
        'max_escalations',
        'escalation_count',
        'last_escalated_at',
        'resolved_at',
        'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'scope' => EmergencyScope::class,
            'escalation_interval_minutes' => 'integer',
            'max_escalations' => 'integer',
            'escalation_count' => 'integer',
            'last_escalated_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------- relationships

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function administration(): BelongsTo
    {
        return $this->belongsTo(Administration::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(EmergencyRecipient::class);
    }

    /** A broadcast posts one message into each targeted system room. */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    // ----------------------------------------------------------------- scopes

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /** Still open and still has at least one person who has not acknowledged. */
    public function scopeAwaitingAcknowledgement(Builder $query): Builder
    {
        return $query->unresolved()->whereHas(
            'recipients',
            fn (Builder $r) => $r->whereNull('acknowledged_at')
        );
    }

    // ------------------------------------------------------------- behaviours

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    public function isBroadcast(): bool
    {
        return $this->scope->isBroadcast();
    }

    public function acknowledgedCount(): int
    {
        return $this->recipients()->whereNotNull('acknowledged_at')->count();
    }

    public function pendingCount(): int
    {
        return $this->recipients()->whereNull('acknowledged_at')->count();
    }

    public function recipientCount(): int
    {
        return $this->recipients()->count();
    }

    public function hasEscalationsRemaining(): bool
    {
        return $this->escalation_count < $this->max_escalations;
    }

    /** Everyone has acknowledged, so there is nothing left to chase. */
    public function isFullyAcknowledged(): bool
    {
        return $this->pendingCount() === 0;
    }

    public function targetLabel(): string
    {
        return match ($this->scope) {
            EmergencyScope::Room, EmergencyScope::Dm => $this->room?->name ?? 'Conversation',
            EmergencyScope::Company => $this->company?->name ?? 'Company',
            EmergencyScope::Administration => $this->administration?->name ?? 'Administration',
            EmergencyScope::All => 'Everyone',
        };
    }
}
