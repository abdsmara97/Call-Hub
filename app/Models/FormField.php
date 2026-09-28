<?php

namespace App\Models;

use App\Enums\FormFieldType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormField extends Model
{
    protected $fillable = ['form_id', 'type', 'label', 'help', 'required', 'position'];

    protected function casts(): array
    {
        return [
            'type' => FormFieldType::class,
            'required' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(FormAnswer::class);
    }
}
