<?php

namespace App\Services;

use App\Events\MessageDeleted;
use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Events\ReadCursorUpdated;
use App\Models\Attachment;
use App\Models\Emergency;
use App\Models\Message;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MessageService
{
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

            $room->forceFill(['last_message_at' => now()])->save();

            // Writing your own message counts as having read it.
            $this->markRead($room, $author, $message->getKey(), broadcast: false);

            return $message;
        });

        broadcast(new MessageSent($message))->toOthers();

        return $message->load(['author', 'attachments']);
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
