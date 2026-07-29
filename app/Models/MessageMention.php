<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageMention extends Model
{
    protected $fillable = ['message_id', 'user_id', 'start', 'length', 'read_at'];

    protected function casts(): array
    {
        return ['start' => 'integer', 'length' => 'integer', 'read_at' => 'datetime'];
    }

    public function scopeUnread(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereNull('read_at');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
