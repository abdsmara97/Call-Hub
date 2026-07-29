<?php

namespace App\Services;

use App\Events\MessageDeleted;
use App\Events\MessageReacted;
use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Events\ReadCursorUpdated;
use App\Models\Attachment;
use App\Models\Emergency;
use App\Models\Message;
use App\Models\MessageMention;
use App\Models\MessageReaction;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MessageService
{
    public function __construct(
        private readonly MentionParser $mentions = new MentionParser,
    ) {}

    /**
     * @param  array<int, UploadedFile>  $uploads
     */
    public function send(
        User $author,
        Room $room,
        ?string $body,
        array $uploads = [],
        ?int $parentId = null,
        ?Emergency $emergency = null,
    ): Message {
        $body = $this->normaliseBody($body);

        if (blank($body) && $uploads === []) {
            throw new \InvalidArgumentException('A message needs text or an attachment.');
        }

        $message = DB::transaction(function () use ($author, $room, $body, $uploads, $parentId, $emergency) {
            $message = Message::create([
                'room_id' => $room->getKey(),
                'user_id' => $author->getKey(),
                'parent_id' => $this->resolveParentId($room, $parentId),
                'emergency_id' => $emergency?->getKey(),
                'body' => $body,
            ]);

            foreach ($uploads as $upload) {
                $this->storeAttachment($message, $upload);
            }

            $message->setRelation('room', $room);
            $this->syncMentions($message);

            $room->forceFill(['last_message_at' => now()])->save();

            // Writing your own message counts as having read it.
            $this->markRead($room, $author, $message->getKey(), broadcast: false);

            return $message;
        });

        broadcast(new MessageSent($message))->toOthers();

        return $message->load(['author', 'attachments', 'mentions']);
    }

    public function edit(Message $message, ?string $body): Message
    {
        $body = $this->normaliseBody($body);

        if (blank($body) && $message->attachments()->doesntExist()) {
            throw new \InvalidArgumentException('A message needs text or an attachment.');
        }

        $message->forceFill([
            'body' => $body,
            'edited_at' => now(),
        ])->save();

        // Recomputed, not left alone: editing a name out has to stop the ping,
        // and editing one in has to start it.
        $this->syncMentions($message);

        broadcast(new MessageUpdated($message))->toOthers();

        return $message;
    }

    /**
     * Soft delete only. Threads keep their shape and the emergency audit trail
     * cannot be hollowed out by deleting the rendered message.
     */
    public function delete(Message $message): void
    {
        $roomId = $message->room_id;
        $id = $message->getKey();

        $message->delete();

        broadcast(new MessageDeleted($id, $roomId))->toOthers();
    }

    /**
     * Records who a message names, and where.
     *
     * Candidates are queried fresh rather than read off a loaded relation, so a
     * caller holding a stale room cannot widen who gets mentioned.
     *
     * Existing rows are diffed rather than deleted and reinserted, which keeps
     * `read_at` on any mention that survived an edit — fixing a typo must not
     * resurface a mention the recipient already dealt with.
     */
    public function syncMentions(Message $message): void
    {
        $spans = blank($message->body)
            ? []
            : $this->mentions->parse(
                $message->body,
                $message->room->members()->get(['users.id', 'users.name']),
            );

        $existing = MessageMention::query()
            ->where('message_id', $message->getKey())
            ->get()
            ->keyBy(fn (MessageMention $m) => $m->user_id.':'.$m->start);

        $keep = [];

        foreach ($spans as $span) {
            $key = $span['user_id'].':'.$span['start'];
            $keep[] = $key;

            if ($existing->has($key)) {
                continue;
            }

            MessageMention::create([
                'message_id' => $message->getKey(),
                'user_id' => $span['user_id'],
                'start' => $span['start'],
                'length' => $span['length'],
            ]);
        }

        $existing->reject(fn ($m, string $key) => in_array($key, $keep, true))
            ->each(fn (MessageMention $m) => $m->delete());

        $message->unsetRelation('mentions');
    }

    /**
     * Adds the reaction, or removes it if the person already reacted with that
     * emoji. Returns true when the reaction now stands.
     */
    public function toggleReaction(Message $message, User $user, string $emoji): bool
    {
        $emoji = $this->normaliseEmoji($emoji);

        $existing = MessageReaction::query()
            ->where('message_id', $message->getKey())
            ->where('user_id', $user->getKey())
            ->where('emoji', $emoji)
            ->first();

        if ($existing) {
            $existing->delete();
            $added = false;
        } else {
            MessageReaction::create([
                'message_id' => $message->getKey(),
                'user_id' => $user->getKey(),
                'emoji' => $emoji,
            ]);
            $added = true;
        }

        broadcast(new MessageReacted($message->getKey(), $message->room_id))->toOthers();

        return $added;
    }

    /**
     * Reactions are pictographs, not free text.
     *
     * Validated by what the string *is* rather than what it is not: every
     * codepoint must be either a pictograph or one of the modifiers that dress
     * one (variation selector, zero-width joiner, skin tone, keycap), and at
     * least one must be a pictograph in its own right.
     *
     * An exclusion list was the first attempt and it let `<script>` through —
     * angle brackets are symbols, not punctuation. Blade would have escaped it,
     * but storing it at all is wrong.
     *
     * @throws \InvalidArgumentException
     */
    private function normaliseEmoji(string $emoji): string
    {
        $emoji = trim($emoji);

        if ($emoji === '') {
            throw new \InvalidArgumentException('Pick an emoji to react with.');
        }

        // A ZWJ family sequence is legitimately several codepoints; well beyond
        // that is somebody probing.
        if (mb_strlen($emoji) > 8) {
            throw new \InvalidArgumentException('That is not a usable reaction.');
        }

        $codepoints = array_map(
            fn (string $c) => mb_ord($c, 'UTF-8'),
            preg_split('//u', $emoji, -1, PREG_SPLIT_NO_EMPTY) ?: [],
        );

        $hasPictograph = false;

        foreach ($codepoints as $cp) {
            if ($cp === false) {
                throw new \InvalidArgumentException('That is not a usable reaction.');
            }

            if ($this->isPictograph($cp)) {
                $hasPictograph = true;

                continue;
            }

            if (! $this->isEmojiModifier($cp)) {
                throw new \InvalidArgumentException('Reactions have to be an emoji.');
            }
        }

        if (! $hasPictograph) {
            throw new \InvalidArgumentException('Reactions have to be an emoji.');
        }

        return $emoji;
    }

    /** The blocks emoji actually live in. */
    private function isPictograph(int $cp): bool
    {
        return ($cp >= 0x1F000 && $cp <= 0x1FAFF)   // pictographs, emoticons, transport, supplemental
            || ($cp >= 0x2600 && $cp <= 0x27BF)     // misc symbols and dingbats
            || ($cp >= 0x2B00 && $cp <= 0x2BFF)     // misc symbols and arrows
            || ($cp >= 0x2190 && $cp <= 0x21FF)     // arrows
            || ($cp >= 0x2300 && $cp <= 0x23FF)     // misc technical, incl. ⏰ ⏳
            || ($cp >= 0x2900 && $cp <= 0x297F)     // supplemental arrows
            || $cp === 0x00A9 || $cp === 0x00AE     // © ®
            || $cp === 0x2122;                      // ™
    }

    /** Characters that only ever decorate a pictograph. */
    private function isEmojiModifier(int $cp): bool
    {
        return $cp === 0xFE0F || $cp === 0xFE0E        // variation selectors
            || $cp === 0x200D                          // zero-width joiner
            || ($cp >= 0x1F3FB && $cp <= 0x1F3FF)      // skin tones
            || $cp === 0x20E3                          // combining enclosing keycap
            || ($cp >= 0xE0020 && $cp <= 0xE007F);     // tag characters, for flags
    }

    /** Moves the reader's cursor forward. Never backwards. */
    public function markRead(Room $room, User $user, ?int $messageId = null, bool $broadcast = true): void
    {
        $messageId ??= $room->messages()->max('id');

        if (! $messageId) {
            return;
        }

        $membership = RoomMember::query()
            ->where('room_id', $room->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        if (! $membership || (int) $membership->last_read_message_id >= (int) $messageId) {
            return;
        }

        $membership->forceFill(['last_read_message_id' => $messageId])->save();

        if ($broadcast) {
            broadcast(new ReadCursorUpdated($room->getKey(), $user->getKey(), $messageId))->toOthers();
        }
    }

    private function storeAttachment(Message $message, UploadedFile $upload): Attachment
    {
        $disk = config('hub.attachments.disk');

        // store() generates a random name — the user's filename is kept for
        // display only and never touches the filesystem.
        $path = $upload->store('messages/'.$message->room_id, $disk);

        $dimensions = @getimagesize($upload->getRealPath()) ?: null;

        return Attachment::create([
            'message_id' => $message->getKey(),
            'disk' => $disk,
            'path' => $path,
            'original_name' => mb_substr($upload->getClientOriginalName(), 0, 255),
            'mime_type' => $upload->getMimeType() ?: 'application/octet-stream',
            'size' => $upload->getSize() ?: 0,
            'width' => $dimensions[0] ?? null,
            'height' => $dimensions[1] ?? null,
        ]);
    }

    /**
     * Threading is one level deep: replying to a reply attaches to the same
     * root, which keeps the UI readable and the query flat.
     */
    private function resolveParentId(Room $room, ?int $parentId): ?int
    {
        if (! $parentId) {
            return null;
        }

        $parent = Message::query()
            ->where('room_id', $room->getKey())
            ->find($parentId);

        return $parent?->parent_id ?? $parent?->getKey();
    }

    private function normaliseBody(?string $body): ?string
    {
        $body = trim((string) $body);

        if ($body === '') {
            return null;
        }

        return mb_substr($body, 0, (int) config('hub.messages.max_length'));
    }

    public function deleteAttachmentFile(Attachment $attachment): void
    {
        Storage::disk($attachment->disk)->delete($attachment->path);
    }
}
