<div>
    <div class="mx-auto max-w-6xl px-4 py-6">
        <x-panel-heading title="Saved messages"
                         description="Messages you bookmarked, newest first. Only you can see this list." />

        <div role="status" aria-live="polite">
            @if ($status)
                <p class="mb-4 flex items-start gap-2 rounded-md bg-brand-tint px-3 py-2 text-sm font-medium text-brand-text">
                    <x-icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>{{ $status }}</span>
                </p>
            @endif
        </div>

        @if ($saved->isEmpty())
            <div class="panel flex flex-col items-center gap-3 px-6 py-14 text-center">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-surface-sunken">
                    <x-icon name="bookmark" class="h-6 w-6 text-content-subtle" />
                </span>
                <h2 class="text-lg font-semibold">Nothing saved yet</h2>
                <p class="max-w-md text-sm text-content-muted">
                    To save a message, open its action menu in the room — the “…” button that appears
                    when you hover over or focus a message — and choose <strong class="font-semibold">Save</strong>.
                    It will show up here.
                </p>
                <p class="max-w-md text-xs text-content-subtle">
                    Saved messages disappear from this list if the message is deleted or you lose
                    access to its room.
                </p>
                <a href="{{ route('hub') }}" class="btn-secondary mt-1">
                    <x-icon name="hash" class="h-4 w-4" />
                    Go to messages
                </a>
            </div>
        @else
            <ul role="list" class="space-y-3">
                @foreach ($saved as $entry)
                    @php
                        $message = $entry->message;
                        $room = $message->room;
                        $roomName = $room->displayNameFor(auth()->user());
                        $permalink = route('rooms.show', $room).'#message-'.$message->id;
                    @endphp

                    <li class="panel p-4">
                        <div class="flex items-start gap-3">
                            @if ($message->author)
                                <x-avatar :user="$message->author" size="md" />
                            @else
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full
                                             bg-surface-sunken text-xs font-semibold text-content-subtle"
                                      aria-hidden="true">?</span>
                            @endif

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                    <span class="text-sm font-semibold text-content">
                                        {{ $message->author?->name ?? 'Former employee' }}
                                    </span>

                                    <span class="badge-neutral">
                                        @if ($room->isDm())
                                            Direct message · {{ $roomName }}
                                        @else
                                            {{ $roomName }}
                                        @endif
                                    </span>

                                    @if ($message->isEmergency())
                                        <span class="badge-emergency">
                                            <x-icon name="alert" class="h-3 w-3" />
                                            Emergency
                                        </span>
                                    @endif

                                    <time datetime="{{ $message->created_at?->toIso8601String() }}"
                                          title="{{ $message->created_at?->toDayDateTimeString() }}"
                                          class="text-2xs text-content-subtle">
                                        {{ $message->created_at?->diffForHumans() }}
                                    </time>
                                </div>

                                <x-message-body :message="$message" class="mt-1.5 text-base text-content" />

                                <div class="mt-3 flex flex-wrap items-center gap-2">
                                    <a href="{{ $permalink }}"
                                       class="btn-secondary !py-1.5 text-xs"
                                       aria-label="Open this message in {{ $roomName }}">
                                        <x-icon name="reply" class="h-4 w-4" />
                                        Open in {{ $room->isDm() ? 'conversation' : 'room' }}
                                    </a>

                                    <button type="button"
                                            wire:click="unsave({{ $entry->id }})"
                                            class="btn-ghost !py-1.5 text-xs"
                                            aria-label="Unsave the message from {{ $message->author?->name ?? 'a former employee' }} in {{ $roomName }}">
                                        <x-icon name="bookmark" class="h-4 w-4" />
                                        Unsave
                                    </button>

                                    <span class="ml-auto text-2xs text-content-subtle">
                                        Saved {{ $entry->created_at?->diffForHumans() }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="mt-6">
                {{ $saved->onEachSide(1)->links() }}
            </div>
        @endif
    </div>
</div>
