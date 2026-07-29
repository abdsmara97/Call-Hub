@php $me = auth()->user(); @endphp

<div class="flex h-full flex-col">
    {{-- Navigation is intentionally dense: tight gutters, small type. --}}
    <div class="shrink-0 space-y-2 border-b border-line p-2">
        <label for="room-filter" class="sr-only">Filter conversations</label>
        <div class="relative">
            <x-icon name="search" class="pointer-events-none absolute left-2 top-1/2 h-4 w-4 -translate-y-1/2 text-content-subtle" />
            <input id="room-filter" type="search" wire:model.live.debounce.200ms="filter"
                   placeholder="Filter conversations"
                   class="field !py-1.5 !pl-8 !text-sm">
        </div>

        <div class="flex gap-1">
            <button type="button" wire:click="$toggle('showCreate')" class="btn-secondary flex-1 !py-1.5 !text-xs">
                <x-icon name="plus" class="h-3.5 w-3.5" /> New room
            </button>
            <button type="button" wire:click="$toggle('showBrowse')" class="btn-secondary flex-1 !py-1.5 !text-xs">
                <x-icon name="hash" class="h-3.5 w-3.5" /> Browse
            </button>
        </div>
    </div>

    @if ($showCreate)
        <form wire:submit="createRoom" class="shrink-0 space-y-2 border-b border-line bg-surface p-2">
            <div>
                <label for="new-room-name" class="label">Room name</label>
                <input id="new-room-name" wire:model="newRoomName" class="field !py-1.5 !text-sm" required>
                <x-input-error :messages="$errors->get('newRoomName')" />
            </div>
            <div>
                <label for="new-room-topic" class="label">Topic <span class="normal-case text-content-subtle">(optional)</span></label>
                <input id="new-room-topic" wire:model="newRoomTopic" class="field !py-1.5 !text-sm">
                <x-input-error :messages="$errors->get('newRoomTopic')" />
            </div>
            <fieldset>
                <legend class="label">Visibility</legend>
                <div class="flex gap-3 text-sm">
                    <label class="flex items-center gap-1.5">
                        <input type="radio" wire:model="newRoomType" value="public" class="text-brand focus:ring-brand/40">
                        Public
                    </label>
                    <label class="flex items-center gap-1.5">
                        <input type="radio" wire:model="newRoomType" value="private" class="text-brand focus:ring-brand/40">
                        Invite only
                    </label>
                </div>
            </fieldset>
            <div class="flex gap-1">
                <button type="submit" class="btn-primary flex-1 !py-1.5 !text-xs">Create</button>
                <button type="button" wire:click="$set('showCreate', false)" class="btn-ghost !py-1.5 !text-xs">Cancel</button>
            </div>
        </form>
    @endif

    @if ($showBrowse)
        <div class="max-h-56 shrink-0 overflow-y-auto border-b border-line bg-surface p-2">
            <p class="mb-1 text-2xs font-semibold uppercase tracking-wide text-content-subtle">Public rooms you can join</p>
            @forelse ($browsable as $room)
                <div class="flex items-center gap-2 rounded-sm px-1 py-1 text-sm">
                    <x-icon name="hash" class="h-3.5 w-3.5 text-content-subtle" />
                    <span class="min-w-0 flex-1 truncate">{{ $room->name }}</span>
                    <span class="text-2xs text-content-subtle">{{ $room->memberships_count }}</span>
                    <button type="button" wire:click="joinRoom({{ $room->id }})"
                            class="btn-ghost !px-1.5 !py-0.5 !text-2xs">Join</button>
                </div>
            @empty
                <p class="px-1 py-2 text-xs text-content-muted">You have joined every public room.</p>
            @endforelse
        </div>
    @endif

    <nav class="min-h-0 flex-1 overflow-y-auto p-2" aria-label="Conversations">
        @php
            $groups = [
                ['label' => 'Your organisation', 'rooms' => $systemRooms, 'icon' => 'users'],
                ['label' => 'Rooms', 'rooms' => $channels, 'icon' => 'hash'],
                ['label' => 'Direct messages', 'rooms' => $conversations, 'icon' => null],
            ];
        @endphp

        @foreach ($groups as $group)
            @if ($group['rooms']->isNotEmpty())
                <h2 class="mb-1 mt-3 px-1 text-2xs font-semibold uppercase tracking-wide text-content-subtle first:mt-0">
                    {{ $group['label'] }}
                </h2>

                <ul class="space-y-0.5">
                    @foreach ($group['rooms'] as $room)
                        @php
                            $count = $unread[$room->id] ?? 0;
                            $isActive = $activeRoomId === $room->id;
                            $other = $room->isDm() ? $room->otherMember($me) : null;
                            $isBlinking = ($blinking[$room->id] ?? false) && ! $isActive;
                        @endphp
                        <li wire:key="room-row-{{ $room->id }}"
                            class="overflow-hidden rounded-md {{ $isBlinking ? 'animate-room-blink' : '' }}"
                            @if ($isBlinking)
                                x-data
                                x-on:animationend="$wire.stopBlinking({{ $room->id }})"
                            @endif>
                            <a href="{{ route('rooms.show', $room) }}" wire:navigate
                               class="{{ $isActive ? 'nav-item-active' : 'nav-item' }} w-full"
                               @if ($isActive) aria-current="page" @endif>
                                @if ($other)
                                    <x-avatar :user="$other" size="xs" :presence="true" />
                                @elseif ($room->is_system)
                                    <x-icon name="users" class="h-3.5 w-3.5 shrink-0" />
                                @elseif ($room->type->value === 'private')
                                    <x-icon name="lock" class="h-3.5 w-3.5 shrink-0" />
                                @else
                                    <x-icon name="hash" class="h-3.5 w-3.5 shrink-0" />
                                @endif

                                <span class="min-w-0 flex-1 truncate {{ $count > 0 ? 'font-semibold text-content' : '' }}">
                                    {{ $room->displayNameFor($me) }}
                                </span>

                                @if ($count > 0)
                                    {{-- The number is the message, not the colour: it is
                                         read out as "N unread messages" either way. --}}
                                    <span class="badge-brand shrink-0 tabular-nums"
                                          aria-label="{{ $count }} unread {{ Str::plural('message', $count) }}">
                                        <span aria-hidden="true">{{ $count > 99 ? '99+' : $count }}</span>
                                    </span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        @endforeach

        @if ($systemRooms->isEmpty() && $channels->isEmpty() && $conversations->isEmpty())
            <p class="px-1 py-4 text-xs text-content-muted">
                @if ($filter !== '')
                    Nothing matches “{{ $filter }}”.
                @else
                    You are not in any rooms yet. Use Browse to join one.
                @endif
            </p>
        @endif
    </nav>
</div>
