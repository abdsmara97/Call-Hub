<?php

namespace App\Models;

use App\Enums\FormFieldType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormAnswer extends Model
{
    protected $fillable = ['form_response_id', 'form_field_id', 'value'];

    public function response(): BelongsTo
    {
        return $this->belongsTo(FormResponse::class, 'form_response_id');
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(FormField::class, 'form_field_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(FormAnswerFile::class);
    }

    /** True when the respondent left this question blank. */
    public function isBlank(): bool
    {
        if ($this->field?->type === FormFieldType::Picture) {
            return $this->relationLoaded('files')
                ? $this->files->isEmpty()
                : $this->files()->doesntExist();
        }

        return blank($this->value);
    }

    /**
     * The answer as a reader should see it. Yes/no is stored as the literal
     * 'yes' or 'no' so it survives a locale change, and is dressed up here.
     */
    public function display(): string
    {
        return match ($this->field?->type) {
            FormFieldType::YesNo => match ($this->value) {
                'yes' => 'Yes',
                'no' => 'No',
                default => '—',
            },
            default => (string) ($this->value ?? ''),
        };
    }
}
