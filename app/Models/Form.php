<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A set of questions, authored in the admin area and independent of any
 * conversation. Where it has been sent is a posting; what people wrote is a
 * response. Both hang off this, and neither is required for it to exist.
 */
class Form extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'created_by', 'title', 'description', 'closes_at'];

    protected function casts(): array
    {
        return ['closes_at' => 'datetime'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class)->orderBy('position');
    }

    /** Every place this form has been sent. */
    public function postings(): HasMany
    {
        return $this->hasMany(FormPosting::class);
    }

    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class, 'form_postings')
            ->withPivot(['message_id', 'posted_by'])
            ->withTimestamps();
    }

    public function responses(): HasMany
    {
        return $this->hasMany(FormResponse::class);
    }

    public function isClosed(): bool
    {
        return $this->closes_at !== null && $this->closes_at->isPast();
    }

    public function isOpen(): bool
    {
        return ! $this->isClosed();
    }

    /** A form nobody has been asked to fill in yet. */
    public function isPosted(): bool
    {
        return $this->relationLoaded('postings')
            ? $this->postings->isNotEmpty()
            : $this->postings()->exists();
    }

    /**
     * Reads an already-loaded relation when the caller eager-loaded it, so a
     * timeline full of forms does not cost a query per row.
     */
    public function responseFor(User $user): ?FormResponse
    {
        return $this->relationLoaded('responses')
            ? $this->responses->firstWhere('user_id', $user->getKey())
            : $this->responses()->where('user_id', $user->getKey())->first();
    }

    public function hasAnswered(User $user): bool
    {
        return $this->responseFor($user)?->isSubmitted() ?? false;
    }

    /** Submitted responses only — a half-finished draft is not an answer. */
    public function responseCount(): int
    {
        return $this->relationLoaded('responses')
            ? $this->responses->filter->isSubmitted()->count()
            : $this->responses()->whereNotNull('submitted_at')->count();
    }

    /**
     * Everyone who could be expected to answer: the membership of every room
     * this form was sent into, counted once even if someone is in two of them.
     *
     * @return Collection<int, User>
     */
    public function audience()
    {
        return $this->rooms()
            ->with('members')
            ->get()
            ->flatMap->members
            ->unique('id')
            ->values();
    }
}
