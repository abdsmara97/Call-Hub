<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An email invitation into a workspace. The token is the whole credential:
 * whoever holds the link may claim the seat, so tokens are single-use,
 * expiring, and replaced (not stacked) when someone is re-invited.
 */
class Invitation extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'email',
        'token',
        'role',
        'company_id',
        'administration_id',
        'invited_by',
        'accepted_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public static function generateToken(): string
    {
        return Str::random(48);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function administration(): BelongsTo
    {
        return $this->belongsTo(Administration::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public function isClaimable(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }
}
