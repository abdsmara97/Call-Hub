<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormResponse extends Model
{
    protected $fillable = ['form_id', 'user_id', 'submitted_at'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime'];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(FormAnswer::class);
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    /** The answer to one question, or null if it was left blank. */
    public function answerTo(FormField $field): ?FormAnswer
    {
        return $this->relationLoaded('answers')
            ? $this->answers->firstWhere('form_field_id', $field->getKey())
            : $this->answers()->where('form_field_id', $field->getKey())->first();
    }
}
