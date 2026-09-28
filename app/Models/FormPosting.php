<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One form, sent into one conversation.
 *
 * Carries the message that announced it, so the card can be rendered in the
 * timeline, and who sent it — which is not necessarily who wrote the form.
 */
class FormPosting extends Model
{
    protected $fillable = ['form_id', 'room_id', 'message_id', 'posted_by'];

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
