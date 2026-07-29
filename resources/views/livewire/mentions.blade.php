<div>
    <div class="mx-auto max-w-6xl px-4 py-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <x-panel-heading title="Mentions"
                             description="Messages where a colleague named you, newest first. Only you can see this list." />

            @if ($unreadCount > 0)
                <button type="button" wire:click="markAllRead" class="btn-secondary shrink-0 !py-1.5 text-xs">
                    <x-icon name="check" class="h-4 w-4" />
                    Mark all read ({{ $unreadCount > 99 ? '99+' : $unreadCount }})
                </button>
            @endif
        </div>

        <div role="status" aria-live="polite">
            @if ($status)
                <p class="mb-4 flex items-start gap-2 rounded-md bg-brand-tint px-3 py-2 text-sm font-medium text-brand-text">
                    <x-icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>{{ $status }}</span>
                </p>
            @endif
        </div>

        @if ($mentions->isEmpty())
            <div class="panel flex flex-col items-center gap-3 px-6 py-14 text-center">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-surface-sunken">
                    <x-icon name="at-symbol" class="h-6 w-6 text-content-subtle" />
                </span>
                <h2 class="text-lg font-semibold">Nobody has mentioned you yet</h2>
                <p class="max-w-md text-sm text-content-muted">
                    When a colleague types <strong class="font-semibold">&#64;</strong> and your name in a
                    room you belong to, the message shows up here.
                </p>
                <p class="max-w-md text-xs text-content-subtle">
                    Mentions disappear from this list if the message is deleted or you lose access to
                    its room.
                </p>
                <a href="{{ route('hub') }}" class="btn-secondary mt-1">
                    <x-icon name="hash" class="h-4 w-4" />
                    Go to messages
                </a>
            </div>
        @else
            <ul role="list" class="space-y-3">
                @foreach ($mentions as $entry)
                    @php
                        $message = $entry->message;
                        $room = $message->room;
                        $roomName = $room->displayNameFor(auth()->user());
                        $permalink = route('rooms.show', $room).'#message-'.$message->id;
                    @endphp

                    <li class="panel p-4 {{ $entry->read_at === null ? 'border-l-4 border-l-brand' : '' }}">
                        <div class="flex gap-3">
                            <x-avatar :user="$message->author" size="md" class="mt-0.5 shrink-0" />

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-baseline gap-x-2">
                                    <span class="text-sm font-semibold">{{ $message->author->name }}</span>
                                    <span class="text-xs text-content-muted">in {{ $roomName }}</span>
                                    <time datetime="{{ $message->created_at?->toIso8601String() }}"
                                          class="text-2xs text-content-subtle">
                                        {{ $message->created_at?->diffForHumans() }}
                                    </time>

                                    @if ($entry->read_at === null)
                                        <span class="badge-brand">New</span>
                                    @endif
                                </div>

                                <x-message-body :message="$message" class="mt-1.5 text-base text-content" />

                                <div class="mt-3">
                                    <a href="{{ $permalink }}"
                                       class="btn-secondary !py-1.5 text-xs"
                                       aria-label="Open this message in {{ $roomName }}">
                                        <x-icon name="reply" class="h-4 w-4" />
                                        Open in {{ $roomName }}
                                    </a>
                                </div>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="mt-4">
                {{ $mentions->links() }}
            </div>
        @endif
    </div>
</div>
