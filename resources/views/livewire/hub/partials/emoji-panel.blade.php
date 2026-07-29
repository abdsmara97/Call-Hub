{{--
    The picker panel itself. Expects to sit inside an x-data="emojiPicker(...)"
    scope, which owns `open`, `query`, `groups`, `results` and `pick()`.

    $align — 'left' (default) or 'right', for panels near the edge of the screen.
    $drop  — 'up' (default) or 'down'.
--}}
@php
    $align ??= 'left';
    $drop ??= 'up';
@endphp

<div x-show="open"
     x-cloak
     x-transition.opacity.duration.100ms
     @click.outside="close()"
     @keydown.escape.window="close()"
     class="absolute z-dropdown w-72 rounded-lg border border-line bg-surface-raised p-2 shadow-lg
            {{ $drop === 'up' ? 'bottom-full mb-2' : 'top-full mt-2' }}
            {{ $align === 'right' ? 'right-0' : 'left-0' }}"
     role="dialog"
     aria-label="Choose an emoji">

    <label for="emoji-search-{{ $panelId }}" class="sr-only">Search emoji</label>
    <input id="emoji-search-{{ $panelId }}"
           type="search"
           x-ref="search"
           x-model="query"
           placeholder="Search…"
           autocomplete="off"
           class="field !py-1.5 !text-sm">

    <div class="mt-2 max-h-56 overflow-y-auto">
        {{-- Search results replace the groups entirely; scanning categories is
             not useful once someone has typed. --}}
        <template x-if="results !== null">
            <div>
                <template x-if="results.length === 0">
                    <p class="px-1 py-4 text-center text-xs text-content-muted">
                        Nothing matches that.
                    </p>
                </template>

                <div class="grid grid-cols-8 gap-0.5">
                    <template x-for="emoji in results" :key="emoji">
                        <button type="button"
                                x-on:click="pick(emoji)"
                                x-text="emoji"
                                class="rounded p-1 text-xl leading-none hover:bg-surface-hover
                                       focus:outline-none focus:ring-2 focus:ring-brand/40"></button>
                    </template>
                </div>
            </div>
        </template>

        <template x-if="results === null">
            <div class="space-y-2">
                <template x-for="group in groups" :key="group.name">
                    <div>
                        <h3 class="px-1 pb-1 text-2xs font-semibold uppercase tracking-wide text-content-subtle"
                            x-text="group.name"></h3>
                        <div class="grid grid-cols-8 gap-0.5">
                            <template x-for="emoji in group.emoji" :key="emoji">
                                <button type="button"
                                        x-on:click="pick(emoji)"
                                        x-text="emoji"
                                        class="rounded p-1 text-xl leading-none hover:bg-surface-hover
                                               focus:outline-none focus:ring-2 focus:ring-brand/40"></button>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </template>
    </div>
</div>
