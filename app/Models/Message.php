<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Message extends Model
{
    use HasFactory, Searchable, SoftDeletes;

    protected $fillable = [
        'room_id',
        'user_id',
        'parent_id',
        'emergency_id',
        'body',
        'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------- relationships

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->oldest();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function emergency(): BelongsTo
    {
        return $this->belongsTo(Emergency::class);
    }

    /**
     * A poll is posted as an ordinary message carrying the question, so it
     * threads, pins, saves and searches like anything else in the room.
     */
    public function poll(): HasOne
    {
        return $this->hasOne(Poll::class);
    }

    /**
     * A form sent into this room is announced the same way a poll is, and for
     * the same reasons. The message points at the posting rather than the form,
     * because the same form can be announced in several rooms at once.
     */
    public function formPosting(): HasOne
    {
        return $this->hasOne(FormPosting::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(MessageMention::class);
    }

    public function mentionsUser(User $user): bool
    {
        return $this->relationLoaded('mentions')
            ? $this->mentions->contains('user_id', $user->getKey())
            : $this->mentions()->where('user_id', $user->getKey())->exists();
    }

    /**
     * The body split into plain runs, mentions and links, in order.
     *
     * The view renders each piece with `{{ }}`, so the app keeps its property of
     * never emitting raw HTML — highlighting a mention must not become an
     * escape-then-inject-markup path. A link segment is the one piece carrying
     * something other than display text, and `url` is the sanitised address
     * rather than what the author typed; see linkSegments().
     *
     * @return list<array{type: 'text'|'mention'|'link', text: string, url: string|null, user_id: int|null}>
     */
    public function bodySegments(): array
    {
        $body = (string) $this->body;

        if ($body === '') {
            return [];
        }

        $mentions = $this->relationLoaded('mentions') ? $this->mentions : $this->mentions()->get();

        if ($mentions->isEmpty()) {
            return self::linkSegments($body);
        }

        $length = mb_strlen($body);
        $segments = [];
        $offset = 0;

        // One span per position: two members sharing a name produce two rows at
        // the same offset, and the text can only be highlighted once.
        $spans = $mentions
            ->sortBy('start')
            ->unique(fn (MessageMention $m) => $m->start)
            ->values();

        foreach ($spans as $mention) {
            $start = (int) $mention->start;
            $span = (int) $mention->length;

            // Defensive: a row that no longer fits the body is dropped rather
            // than used to slice garbage out of the middle of a word.
            if ($start < $offset || $span < 1 || $start + $span > $length) {
                continue;
            }

            if ($start > $offset) {
                array_push($segments, ...self::linkSegments(mb_substr($body, $offset, $start - $offset)));
            }

            $segments[] = [
                'type' => 'mention',
                'text' => mb_substr($body, $start, $span),
                'url' => null,
                'user_id' => (int) $mention->user_id,
            ];

            $offset = $start + $span;
        }

        if ($offset < $length) {
            array_push($segments, ...self::linkSegments(mb_substr($body, $offset)));
        }

        return $segments;
    }

    /**
     * A run of plain text split into ordinary text and the links inside it.
     *
     * Only http(s) and bare `www.` addresses are recognised, and the `url` a
     * link segment carries is either one this method matched with an http(s)
     * scheme or one it wrote the scheme onto itself. So `javascript:` and
     * `data:` bodies cannot reach an href — escaping alone would not have
     * stopped them, because escaping a URL leaves its scheme intact.
     *
     * Trailing punctuation is pushed back into the text run: people end
     * sentences with a link, and "watch this (youtu.be/abc)." should not put
     * the bracket and full stop inside the address.
     *
     * @return list<array{type: 'text'|'link', text: string, url: string|null, user_id: null}>
     */
    private static function linkSegments(string $text): array
    {
        if ($text === '' || ! preg_match_all('~\b(?:https?://|www\.)[^\s<>]+~iu', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return $text === '' ? [] : [['type' => 'text', 'text' => $text, 'url' => null, 'user_id' => null]];
        }

        $segments = [];
        $offset = 0;

        // Byte offsets throughout: preg_match_all reports them in bytes, and a
        // match always begins and ends on a character boundary, so substr() is
        // safe here in a way it would not be on an arbitrary index.
        foreach ($matches[0] as [$match, $start]) {
            $url = self::trimUrlTail($match);

            // A scheme with no host ("https://") is text, not a link.
            if (! preg_match('~^(?:https?://|www\.)[^\s/?#]+~i', $url)) {
                continue;
            }

            if ($start > $offset) {
                $segments[] = ['type' => 'text', 'text' => substr($text, $offset, $start - $offset), 'url' => null, 'user_id' => null];
            }

            $segments[] = [
                'type' => 'link',
                'text' => $url,
                'url' => str_starts_with(strtolower($url), 'www.') ? 'https://'.$url : $url,
                'user_id' => null,
            ];

            $offset = $start + strlen($url);
        }

        if ($offset < strlen($text)) {
            $segments[] = ['type' => 'text', 'text' => substr($text, $offset), 'url' => null, 'user_id' => null];
        }

        return $segments;
    }

    /**
     * Punctuation a URL collected from the sentence around it.
     */
    private static function trimUrlTail(string $url): string
    {
        $openers = [')' => '(', ']' => '[', '}' => '{'];

        while ($url !== '') {
            $last = $url[strlen($url) - 1];

            if (str_contains('.,;:!?\'"', $last)) {
                $url = substr($url, 0, -1);

                continue;
            }

            // A closing bracket stays only if the URL opened one — Wikipedia
            // addresses really do end in ")".
            if (isset($openers[$last]) && substr_count($url, $openers[$last]) < substr_count($url, $last)) {
                $url = substr($url, 0, -1);

                continue;
            }

            break;
        }

        return $url;
    }

    /**
     * Reactions grouped for display: one entry per emoji, in the order each was
     * first used, with who reacted and whether the viewer is among them.
     *
     * Reads the loaded relation when the caller eager-loaded it, so rendering a
     * page of messages does not cost a query per row.
     *
     * @return list<array{emoji: string, count: int, mine: bool, who: string}>
     */
    public function reactionSummary(User $viewer): array
    {
        $reactions = $this->relationLoaded('reactions')
            ? $this->reactions
            : $this->reactions()->with('user')->get();

        return $reactions
            ->sortBy('id')
            ->groupBy('emoji')
            ->map(fn ($group, $emoji) => [
                'emoji' => (string) $emoji,
                'count' => $group->count(),
                'mine' => $group->contains('user_id', $viewer->getKey()),
                'who' => $group
                    ->map(fn (MessageReaction $r) => $r->user_id === $viewer->getKey()
                        ? 'You'
                        : ($r->user?->name ?? 'Someone'))
                    ->join(', ', ' and '),
            ])
            ->values()
            ->all();
    }

    // ----------------------------------------------------------------- scopes

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /** Restricts a query to rooms the user can actually read. */
    public function scopeReadableBy(Builder $query, User $user): Builder
    {
        return $query->whereHas(
            'room',
            fn (Builder $room) => $room->visibleTo($user)
        );
    }

    // ------------------------------------------------------------- behaviours

    public function isEmergency(): bool
    {
        return $this->emergency_id !== null;
    }

    public function wasEdited(): bool
    {
        return $this->edited_at !== null;
    }

    public function isThreadReply(): bool
    {
        return $this->parent_id !== null;
    }

    // ------------------------------------------------------------------ scout

    /**
     * The body plus the tenant fence. Access control is still applied at
     * query time by intersecting hits with the rooms the searcher belongs
     * to — never trust the index itself to enforce authorisation — but the
     * tenant_id in the payload lets every search be cut to one customer
     * before that intersection happens.
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->getKey(),
            'body' => (string) $this->body,
            'tenant_id' => $this->room?->tenant_id,
            'room_id' => $this->room_id,
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return filled($this->body);
    }

    /**
     * The only sanctioned way to full-text search messages: the tenant
     * filter is part of the call signature, not something a caller can
     * forget. One shared index, hard-fenced per customer.
     */
    public static function searchInTenant(int $tenantId, string $term): \Laravel\Scout\Builder
    {
        return static::search($term)->where('tenant_id', $tenantId);
    }
}
