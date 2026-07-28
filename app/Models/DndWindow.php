<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recurring quiet period. Suppresses ordinary notifications only — emergency
 * delivery ignores DND entirely, which is the whole point of the override.
 */
class DndWindow extends Model
{
    protected $fillable = ['user_id', 'day_of_week', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return ['day_of_week' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dayName(): string
    {
        return ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$this->day_of_week] ?? '';
    }
}
