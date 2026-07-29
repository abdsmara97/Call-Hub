@php
    $me = auth()->user();
    $isEmergency = $message->isEmergency();
    $isSaved = in_array($message->id, $savedIds ?? [], true);
@endphp

<article id="message-{{ $message->id }}"
         class="group relative px-4 py-2 transition-colors duration-fast
                {{ $isEmergency
                    ? 'border-l-4 border-emergency bg-emergency-tint'
                    : 'border-l-4 border-transparent hover:bg-surface-hover' }}"
         @if ($isEmergency) aria-label="Emergency message" @endif>

    <div class="flex gap-3">
        <x-avatar :user="$message->author" size="md" class="mt-0.5" />

        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-baseline gap-x-2">
                <span class="text-sm font-semibold">{{ $message->author->name }}</span>

                <time datetime="{{ $message->created_at->toIso8601String() }}"
                      class="text-2xs text-content-subtle"
                      title="{{ $message->created_at->toDayDateTimeString() }}">
                    {{ $message->created_at->format('H:i') }}
                </time>

                @if ($message->wasEdited())
                    <span class="text-2xs text-content-subtle">(edited)</span>
                @endif

                @if ($isEmergency)
                    {{-- Colour is never the only signal: icon + word + border too. --}}
                    <span class="badge-emergency">
                        <x-icon name="alert" class="h-3 w-3" />
                        Emergency
                    </span>
                @endif
            </div>

            @if ($editing === $message->id)
                <form wire:submit="saveEdit" class="mt-1 space-y-2">
                    <label for="edit-{{ $message->id }}" class="sr-only">Edit message</label>
                    <textarea id="edit-{{ $message->id }}" wire:model="editBody" rows="2"
                              class="field text-base" autofocus
                              @keydown.escape="$wire.cancelEdit()"></textarea>
                    <x-input-error :messages="$errors->get('editBody')" />
                    <div class="flex gap-2">
                        <button type="submit" class="btn-primary !py-1 !text-xs">Save</button>
                        <button type="button" wire:click="cancelEdit" class="btn-ghost !py-1 !text-xs">Cancel</button>
                    </div>
                </form>
            @else
                {{-- A poll's body is its question, which the card already shows. --}}
                @if (filled($message->body) && ! $message->poll)
                    <x-message-body :message="$message"
                                    class="mt-0.5 text-base {{ $isEmergency ? 'font-medium text-content' : 'text-content' }}" />
                @endif

                @if ($message->attachments->isNotEmpty())
                    <ul class="mt-2 flex flex-wrap gap-2">
                        @foreach ($message->attachments as $attachment)
                            <li>
                                @if ($attachment->isImage())
                                    <a href="{{ $attachment->temporaryUrl() }}" target="_blank" rel="noopener noreferrer"
                                       class="block overflow-hidden rounded-md border border-line">
                                        <img src="{{ $attachment->temporaryUrl() }}"
                                             alt="{{ $attachment->original_name }}"
                                             loading="lazy"
                                             class="max-h-56 max-w-xs object-cover">
                                    </a>
                                @else
                                    <a href="{{ $attachment->temporaryUrl() }}"
                                       class="flex items-center gap-2 rounded-md border border-line bg-surface px-2.5 py-1.5 text-xs hover:bg-surface-hover">
                                        <x-icon name="document" class="h-4 w-4 text-content-subtle" />
                                        <span class="max-w-[16rem] truncate">{{ $attachment->original_name }}</span>
                                        <span class="text-content-subtle">{{ $attachment->humanSize() }}</span>
                                    </a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($message->poll)
                    @include('livewire.hub.partials.poll-card', ['poll' => $message->poll])
                @endif
            @endif

            {{-- Reactions. The chip carries the count as text, so it never
                 depends on being able to tell the emoji apart at 14px. --}}
            @php $reactions = $message->reactionSummary($me); @endphp

            @if ($reactions || $me->can('react', $message))
                <div class="mt-1.5 flex flex-wrap items-center gap-1">
                    @foreach ($reactions as $reaction)
                        <button type="button"
                                @can('react', $message)
                                    wire:click="react({{ $message->id }}, '{{ $reaction['emoji'] }}')"
                                @else
                                    disabled
                                @endcan
                                class="flex items-center gap-1 rounded-full border px-1.5 py-0.5 text-xs
                                       transition-colors duration-fast
                                       {{ $reaction['mine']
                                            ? 'border-brand bg-brand-tint font-medium text-brand-text'
                                            : 'border-line bg-surface-sunken text-content-muted hover:bg-surface-hover' }}"
                                aria-pressed="{{ $reaction['mine'] ? 'true' : 'false' }}"
                                title="{{ $reaction['who'] }} reacted with {{ $reaction['emoji'] }}">
                            <span aria-hidden="true">{{ $reaction['emoji'] }}</span>
                            <span class="tabular-nums">{{ $reaction['count'] }}</span>
                            <span class="sr-only">
                                {{ $reaction['who'] }} reacted with {{ $reaction['emoji'] }}.
                                {{ $reaction['mine'] ? 'Select to remove yours.' : 'Select to add yours.' }}
                            </span>
                        </button>
                    @endforeach

                    @can('react', $message)
                        <div class="relative" wire:ignore
                             x-data="emojiPicker((emoji) => $wire.react({{ $message->id }}, emoji))">
                            <button type="button" x-on:click="toggle()"
                                    class="flex items-center rounded-full border border-dashed border-line
                                           px-1.5 py-0.5 text-content-subtle hover:bg-surface-hover
                                           {{ $reactions ? '' : 'opacity-0 group-hover:opacity-100 focus:opacity-100' }}"
                                    x-bind:aria-expanded="open ? 'true' : 'false'">
                                <x-icon name="face-smile" class="h-3.5 w-3.5" />
                                <span class="sr-only">Add a reaction</span>
                            </button>

                            @include('livewire.hub.partials.emoji-panel', [
                                'panelId' => 'msg-'.$message->id,
                                'drop' => 'down',
                            ])
                        </div>
                    @endcan
                </div>
            @endif

            @if (($message->replies_count ?? 0) > 0)
                <button type="button" wire:click="toggleThread({{ $message->id }})"
                        class="mt-1.5 inline-flex items-center gap-1 rounded-sm text-xs font-medium text-brand-text hover:underline"
                        aria-expanded="{{ isset($openThreads[$message->id]) ? 'true' : 'false' }}">
                    <x-icon name="reply" class="h-3.5 w-3.5" />
                    {{ $message->replies_count }} {{ Str::plural('reply', $message->replies_count) }}
                </button>
            @endif

            @if (isset($openThreads[$message->id]) && $message->replies->isNotEmpty())
                <ul class="mt-2 space-y-2 border-l-2 border-line pl-3">
                    @foreach ($message->replies as $reply)
                        <li class="flex gap-2">
                            <x-avatar :user="$reply->author" size="xs" class="mt-0.5" />
                            <div class="min-w-0">
                                <span class="text-xs font-semibold">{{ $reply->author->name }}</span>
                                <time datetime="{{ $reply->created_at->toIso8601String() }}"
                                      class="ml-1.5 text-2xs text-content-subtle">{{ $reply->created_at->format('H:i') }}</time>
                                <x-message-body :message="$reply" class="text-sm" />
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- Row actions. Visible on hover, and always reachable by keyboard. --}}
        <div class="absolute right-3 top-1 flex items-center gap-0.5 rounded-md border border-line
                    bg-surface-raised p-0.5 opacity-0 shadow-xs transition-opacity duration-fast
                    focus-within:opacity-100 group-hover:opacity-100">
            <button type="button" wire:click="replyInThread({{ $message->id }})"
                    class="btn-ghost !px-1.5 !py-1" aria-label="Reply in thread to {{ $message->author->name }}">
                <x-icon name="reply" class="h-3.5 w-3.5" />
            </button>

            <button type="button" wire:click="toggleSave({{ $message->id }})"
                    class="btn-ghost !px-1.5 !py-1 {{ $isSaved ? 'text-brand-text' : '' }}"
                    aria-label="{{ $isSaved ? 'Remove from saved messages' : 'Save this message' }}"
                    aria-pressed="{{ $isSaved ? 'true' : 'false' }}">
                <x-icon name="bookmark" class="h-3.5 w-3.5" />
            </button>

            @can('pin', $message)
                <button type="button" wire:click="togglePin({{ $message->id }})"
                        class="btn-ghost !px-1.5 !py-1" aria-label="Pin or unpin this message">
                    <x-icon name="pin" class="h-3.5 w-3.5" />
                </button>
            @endcan

            @can('update', $message)
                <button type="button" wire:click="startEdit({{ $message->id }})"
                        class="btn-ghost !px-1.5 !py-1" aria-label="Edit your message">
                    <x-icon name="pencil" class="h-3.5 w-3.5" />
                </button>
            @endcan

            @can('delete', $message)
                <button type="button" wire:click="deleteMessage({{ $message->id }})"
                        wire:confirm="Delete this message? It will show as removed."
                        class="btn-ghost !px-1.5 !py-1 hover:text-emergency-text" aria-label="Delete this message">
                    <x-icon name="trash" class="h-3.5 w-3.5" />
                </button>
            @endcan
        </div>
    </div>
</article>
