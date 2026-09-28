<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

class FormAnswerFile extends Model
{
    protected $fillable = [
        'form_answer_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'width',
        'height',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function answer(): BelongsTo
    {
        return $this->belongsTo(FormAnswer::class, 'form_answer_id');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * Answer files live on the same private disk as attachments. The only way
     * to reach one is a signed, short-lived URL issued after the read check in
     * FormAnswerFileController — a form's answers are narrower than its room.
     */
    public function temporaryUrl(int $minutes = 10): string
    {
        return URL::temporarySignedRoute(
            'forms.files.show',
            now()->addMinutes($minutes),
            ['file' => $this->getKey()],
        );
    }

    public function humanSize(): string
    {
        $bytes = (int) $this->size;
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), $power === 0 ? 0 : 1).' '.$units[$power];
    }
}
