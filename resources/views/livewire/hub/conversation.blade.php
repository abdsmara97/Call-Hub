@php
    $me = auth()->user();
    $room = $this->room;
    $other = $room->isDm() ? $room->otherMember($me) : null;
@endphp

<div class="flex min-h-0 flex-1 flex-col">
    {{-- ------------------------------------------------------------ header --}}
    <header class="flex shrink-0 items-center gap-3 border-b border-line bg-surface px-4 py-2.5">
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
                @if ($other)
                    <x-avatar :user="$other" size="sm" :presence="true" />
                @elseif ($room->is_system)
                    <x-icon name="users" class="h-4 w-4 text-content-subtle" />
                @elseif ($room->type->value === 'private')
                    <x-icon name="lock" class="h-4 w-4 text-content-subtle" />
                @else
                    <x-icon name="hash" class="h-4 w-4 text-content-subtle" />
                @endif

                <h1 class="truncate text-lg font-semibold tracking-tight">{{ $room->displayNameFor($me) }}</h1>

                @if ($room->is_system)
                    <span class="badge-neutral">Automatic</span>
                @endif
            </div>

            @if ($room->topic)
                <p class="truncate text-xs text-content-muted">{{ $room->topic }}</p>
            @elseif ($other)
                <p class="truncate text-xs text-content-muted">
                    {{ $other->job_title }} · {{ $other->administration?->name }}
                </p>
            @endif
        </div>

        <div class="flex items-center gap-1">
            <div class="relative hidden sm:block">
                <label for="conversation-search" class="sr-only">Search this conversation</label>
                <x-icon name="search" class="pointer-events-none absolute left-2 top-1/2 h-4 w-4 -translate-y-1/2 text-content-subtle" />
                <input id="conversation-search" type="search" wire:model.live.debounce.300ms="search"
                       placeholder="Search here" class="field !w-44 !py-1.5 !pl-8 !text-sm">
            </div>

            <span class="hidden items-center gap-1 text-xs text-content-muted md:flex" title="Members">
                <x-icon name="users" class="h-4 w-4" />
                {{ $room->members->count() }}
            </span>

            @can('leave', $room)
                <button type="button" wire:click="leaveRoom" wire:confirm="Leave this room?"
                        class="btn-ghost !px-2 !py-1.5 !text-xs">Leave</button>
            @endcan
        </div>
    </header>

    {{-- ------------------------------------------ pinned emergencies + notes --}}
    @if ($this->pinnedEmergencies->isNotEmpty() || $this->pinnedMessages->isNotEmpty())
        <div class="shrink-0 space-y-2 border-b border-line bg-canvas px-4 py-3">
            @foreach ($this->pinnedEmergencies as $emergency)
                @include('livewire.hub.partials.emergency-banner', ['emergency' => $emergency])
            @endforeach

            @foreach ($this->pinnedMessages as $pin)
                <div class="flex items-start gap-2 rounded-md border border-line bg-surface px-3 py-2">
                    <x-icon name="pin" class="mt-0.5 h-4 w-4 shrink-0 text-content-subtle" />
                    <div class="min-w-0 flex-1">
                        <p class="text-2xs font-semibold uppercase tracking-wide text-content-subtle">
                            Pinned · {{ $pin->message->author->name }}
                        </p>
                        <p class="truncate text-sm">{{ $pin->message->body }}</p>
                    </div>
                    @can('pin', $pin->message)
                        <button type="button" wire:click="togglePin({{ $pin->message_id }})"
                                class="btn-ghost !px-1.5 !py-1" aria-label="Unpin this message">
                            <x-icon name="x" class="h-3.5 w-3.5" />
                        </button>
                    @endcan
                </div>
            @endforeach
        </div>
    @endif

    {{-- ------------------------------------------------------ message list --}}
    <div class="min-h-0 flex-1 overflow-y-auto"
         x-data="{
             stick() { this.$nextTick(() => this.$el.scrollTop = this.$el.scrollHeight); },
         }"
         x-init="stick()"
         @scroll-to-latest.window="stick()">

        @if ($this->timeline->count() >= $limit)
            <div class="p-3 text-center">
                <button type="button" wire:click="loadMore" class="btn-secondary !py-1.5 !text-xs">
                    Load earlier messages
                </button>
            </div>
        @endif

        @forelse ($this->timeline as $message)
            @include('livewire.hub.partials.message-row', [
                'message' => $message,
                'savedIds' => $this->savedMessageIds,
            ])
        @empty
            <div class="p-8 text-center">
                <p class="text-sm text-content-muted">
                    @if ($search !== '')
                        No messages here match “{{ $search }}”.
                    @else
                        No messages yet. Say something.
                    @endif
                </p>
            </div>
        @endforelse
    </div>

    {{-- ---------------------------------------------------------- composer --}}
    @can('post', $room)
        <div class="shrink-0 border-t border-line bg-surface p-4">
            @if ($replyTo)
                <div class="mb-2 flex items-center gap-2 rounded-md bg-surface-sunken px-2.5 py-1.5 text-xs">
                    <x-icon name="reply" class="h-3.5 w-3.5 text-content-subtle" />
                    <span class="text-content-muted">Replying in thread</span>
                    <button type="button" wire:click="cancelReply" class="ml-auto btn-ghost !px-1 !py-0.5"
                            aria-label="Cancel reply">
                        <x-icon name="x" class="h-3 w-3" />
                    </button>
                </div>
            @endif

            {{-- Arming the emergency flag visibly changes the whole composer. --}}
            @if ($emergencyArmed)
                <div class="mb-2 flex items-start gap-2 rounded-md border-2 border-emergency bg-emergency-tint px-3 py-2">
                    <x-icon name="alert" class="mt-0.5 h-5 w-5 shrink-0 text-emergency-text" />
                    <div class="min-w-0 flex-1 text-xs">
                        <p class="font-semibold text-emergency-text">Emergency mode is on.</p>
                        <p class="text-content-muted">
                            Everyone in this conversation will be alerted immediately — with sound and a
                            browser notification — and must acknowledge. Do Not Disturb will not hold it back.
                        </p>
                    </div>
                    <button type="button" wire:click="$set('emergencyArmed', false)"
                            class="btn-ghost !px-1.5 !py-1" aria-label="Turn emergency mode off">
                        <x-icon name="x" class="h-4 w-4" />
                    </button>
                </div>
            @endif

            {{-- Poll composer. Replaces nothing — it sits above the message box
                 so a half-written message is never lost to opening it. --}}
            @if ($pollOpen)
                <div class="mb-2 rounded-md border border-line bg-surface-sunken p-3">
                    <div class="flex items-center gap-2">
                        <x-icon name="chart" class="h-4 w-4 text-content-subtle" />
                        <h2 class="flex-1 text-sm font-semibold">Ask the room</h2>
                        <button type="button" wire:click="closePollComposer"
                                class="btn-ghost !px-1.5 !py-1" aria-label="Discard this poll">
                            <x-icon name="x" class="h-4 w-4" />
                        </button>
                    </div>

                    <form wire:submit="createPoll" class="mt-2.5 space-y-2.5">
                        <div>
                            <label for="poll-question" class="sr-only">Poll question</label>
                            <input id="poll-question" type="text" wire:model="pollQuestion"
                                   placeholder="What should we decide?" class="field !py-1.5 text-sm">
                            <x-input-error :messages="$errors->get('pollQuestion')" />
                        </div>

                        <ul class="space-y-1.5">
                            @foreach ($pollOptions as $index => $option)
                                <li class="flex items-center gap-1.5" wire:key="poll-option-{{ $index }}">
                                    <label for="poll-option-{{ $index }}" class="sr-only">
                                        Option {{ $index + 1 }}
                                    </label>
                                    <input id="poll-option-{{ $index }}" type="text"
                                           wire:model="pollOptions.{{ $index }}"
                                           placeholder="Option {{ $index + 1 }}"
                                           class="field !py-1.5 text-sm">
                                    @if (count($pollOptions) > 2)
                                        <button type="button" wire:click="removePollOption({{ $index }})"
                                                class="btn-ghost !px-1.5 !py-1"
                                                aria-label="Remove option {{ $index + 1 }}">
                                            <x-icon name="x" class="h-3.5 w-3.5" />
                                        </button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        <x-input-error :messages="$errors->get('pollOptions')" />
                        <x-input-error :messages="$errors->get('pollOptions.*')" />

                        <div class="flex flex-wrap items-end gap-2">
                            @if (count($pollOptions) < 10)
                                <button type="button" wire:click="addPollOption"
                                        class="btn-secondary !py-1.5 !text-xs">
                                    <x-icon name="plus" class="h-3.5 w-3.5" />
                                    Add option
                                </button>
                            @endif

                            <div>
                                <label for="poll-closes" class="block text-2xs text-content-muted">
                                    Close automatically (optional)
                                </label>
                                <input id="poll-closes" type="datetime-local" wire:model="pollClosesAt"
                                       class="field !w-auto !py-1.5 !text-xs">
                            </div>

                            <button type="submit" class="btn-primary ml-auto !py-1.5 !text-xs"
                                    wire:loading.attr="disabled" wire:target="createPoll">
                                Post poll
                            </button>
                        </div>
                        <x-input-error :messages="$errors->get('pollClosesAt')" />
                    </form>
                </div>
            @endif

            <form wire:submit="send" class="space-y-2">
                <label for="composer" class="sr-only">Message {{ $room->displayNameFor($me) }}</label>
                <textarea id="composer" wire:model="body" rows="2"
                          placeholder="{{ $emergencyArmed ? 'Describe the emergency…' : 'Write a message…' }}"
                          @keydown.enter.exact.prevent="$wire.send()"
                          class="field resize-y text-base
                                 {{ $emergencyArmed ? '!border-emergency focus:!border-emergency focus:!ring-emergency/30' : '' }}"></textarea>
                <x-input-error :messages="$errors->get('body')" />

                @if ($uploads)
                    <ul class="flex flex-wrap gap-1.5">
                        @foreach ($uploads as $index => $upload)
                            <li class="flex items-center gap-1.5 rounded-md border border-line bg-surface-sunken px-2 py-1 text-xs">
                                <x-icon name="document" class="h-3.5 w-3.5 text-content-subtle" />
                                <span class="max-w-[12rem] truncate">{{ $upload->getClientOriginalName() }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <x-input-error :messages="$errors->get('uploads.*')" />

                <div class="flex items-center gap-2">
                    @unless ($emergencyArmed)
                        <label class="btn-ghost cursor-pointer !px-2" title="Attach files">
                            <x-icon name="paperclip" class="h-4 w-4" />
                            <span class="sr-only">Attach files</span>
                            <input type="file" wire:model="uploads" multiple class="sr-only"
                                   accept="{{ collect(config('hub.attachments.allowed_mimes'))->map(fn ($e) => '.'.$e)->implode(',') }}">
                        </label>

                        {{-- Emoji. Inserts at the caret, so it works mid-sentence. --}}
                        <div class="relative" wire:ignore
                             x-data="emojiPicker((emoji) => {
                                 const box = document.getElementById('composer');
                                 $insertEmoji(box, emoji);
                             })">
                            <button type="button" x-on:click="toggle()"
                                    class="btn-ghost !px-2" title="Insert an emoji"
                                    x-bind:aria-expanded="open ? 'true' : 'false'">
                                <x-icon name="face-smile" class="h-4 w-4" />
                                <span class="sr-only">Insert an emoji</span>
                            </button>

                            @include('livewire.hub.partials.emoji-panel', ['panelId' => 'composer'])
                        </div>

                        <button type="button" wire:click="openPoll"
                                class="btn-ghost !px-2" title="Create a poll"
                                aria-expanded="{{ $pollOpen ? 'true' : 'false' }}">
                            <x-icon name="chart" class="h-4 w-4" />
                            <span class="sr-only">Create a poll</span>
                        </button>
                    @endunless

                    {{-- The emergency control is deliberately separate from Send. --}}
                    <button type="button" wire:click="$toggle('emergencyArmed')"
                            class="{{ $emergencyArmed ? 'btn-emergency' : 'btn-secondary' }} !py-1.5 !text-xs"
                            aria-pressed="{{ $emergencyArmed ? 'true' : 'false' }}">
                        <x-icon name="alert" class="h-4 w-4" />
                        Emergency
                    </button>

                    <div wire:loading wire:target="uploads" class="text-xs text-content-muted">Uploading…</div>

                    <button type="submit"
                            class="{{ $emergencyArmed ? 'btn-emergency' : 'btn-primary' }} ml-auto"
                            wire:loading.attr="disabled" wire:target="send">
                        <x-icon name="send" class="h-4 w-4" />
                        {{ $emergencyArmed ? 'Send emergency' : 'Send' }}
                    </button>
                </div>
            </form>
        </div>
    @else
        <div class="shrink-0 border-t border-line bg-surface-sunken px-4 py-3 text-center text-sm text-content-muted">
            You are viewing this room but have not joined it, so you cannot post.
        </div>
    @endcan
</div>
