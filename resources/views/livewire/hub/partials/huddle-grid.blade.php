{{--
    The participant grid.

    The problem the one-to-one panel never had: N tiles that have to stay legible
    from one person to a dozen, in a panel that is deliberately not a takeover.

    Tile order is join order and never changes. Re-sorting by who is speaking
    would move DOM nodes, and moving a node containing a <video> re-attaches its
    track — visible flicker on every "mm-hm". Speaking sets a ring, nothing more.
--}}

<div x-show="! isStageMode" class="grid min-h-0 flex-1 auto-rows-fr gap-2 p-2" :class="gridColumns">
    <template x-for="tile in tiles" :key="tile.identity">
        <div class="group relative min-h-0 overflow-hidden rounded-lg bg-slate-900 ring-1 ring-brand-border"
             :class="tile.speaking && 'ring-2 ring-brand'">

            {{-- The directive appends LiveKit's own <video>, so the sizing has to
                 be applied to the child rather than to this element. --}}
            <div x-huddle-track="tile.videoSid" x-show="tile.camOn" x-cloak
                 class="absolute inset-0 [&>video]:h-full [&>video]:w-full [&>video]:object-cover"></div>

            {{-- Camera off is the normal case in a work huddle, not a fallback,
                 so it gets the same care as the video path. --}}
            <div x-show="! tile.camOn" class="absolute inset-0 flex items-center justify-center">
                <template x-if="tile.avatar">
                    <img :src="tile.avatar" alt="" class="h-14 w-14 rounded-full object-cover ring-2 ring-white/15" />
                </template>
                <template x-if="! tile.avatar">
                    <span class="flex h-14 w-14 items-center justify-center rounded-full bg-brand-tint
                                 text-lg font-semibold text-brand-text" x-text="tile.initials"></span>
                </template>
            </div>

            {{-- Speaking is never colour alone: the ring is joined by a glyph and
                 the name going semibold. --}}
            <div class="absolute inset-x-0 bottom-0 flex items-center gap-1.5
                        bg-gradient-to-t from-black/70 to-transparent px-2 py-1.5">
                <span x-show="! tile.micOn" class="shrink-0 text-white/90" title="Muted">
                    <x-icon name="microphone-slash" class="h-3.5 w-3.5" />
                </span>
                <span x-show="tile.speaking" class="shrink-0 text-brand">
                    <x-icon name="microphone" class="h-3.5 w-3.5" />
                </span>
                <span class="truncate text-xs text-white" :class="tile.speaking && 'font-semibold'"
                      x-text="tile.name"></span>
                <span x-show="tile.isMe" class="shrink-0 text-xs text-white/60">(you)</span>
            </div>
        </div>
    </template>

    {{-- A tile rather than a footnote: it belongs in the grid's reading order,
         where the eye already is. --}}
    <div x-show="overflow > 0" x-cloak
         class="flex items-center justify-center rounded-lg bg-canvas text-xs font-medium text-content-muted"
         x-text="`+${overflow} more`"></div>
</div>

{{--
    Past twelve people, one large tile and a filmstrip rather than pagination.
    Pagination in a call is a control nobody uses and everybody has to maintain.
--}}
<div x-show="isStageMode" x-cloak class="flex min-h-0 flex-1 flex-col gap-2 p-2">
    <template x-for="tile in tiles.filter((t) => t.identity === stageIdentity)" :key="'stage-' + tile.identity">
        <div class="relative min-h-0 flex-1 overflow-hidden rounded-lg bg-slate-900">
            <div x-huddle-track="tile.screenSid || tile.videoSid" x-show="tile.camOn || tile.screenSid" x-cloak
                 class="absolute inset-0 [&>video]:h-full [&>video]:w-full [&>video]:object-contain"></div>

            <div x-show="! tile.camOn && ! tile.screenSid"
                 class="absolute inset-0 flex items-center justify-center">
                <span class="flex h-20 w-20 items-center justify-center rounded-full bg-brand-tint
                             text-2xl font-semibold text-brand-text" x-text="tile.initials"></span>
            </div>

            <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent px-3 py-2">
                <span class="text-xs font-semibold text-white" x-text="tile.name"></span>
            </div>
        </div>
    </template>

    <div class="flex shrink-0 gap-1.5 overflow-x-auto pb-1">
        <template x-for="tile in tiles.filter((t) => t.identity !== stageIdentity).slice(0, 8)"
                  :key="'strip-' + tile.identity">
            <div class="relative h-16 w-24 shrink-0 overflow-hidden rounded-md bg-slate-900 ring-1 ring-brand-border"
                 :class="tile.speaking && 'ring-2 ring-brand'">
                <div x-huddle-track="tile.videoSid" x-show="tile.camOn" x-cloak
                     class="absolute inset-0 [&>video]:h-full [&>video]:w-full [&>video]:object-cover"></div>
                <div x-show="! tile.camOn" class="absolute inset-0 flex items-center justify-center">
                    <span class="text-xs font-semibold text-white/80" x-text="tile.initials"></span>
                </div>
            </div>
        </template>

        <div x-show="overflow > 0" x-cloak
             class="flex h-16 w-16 shrink-0 items-center justify-center rounded-md bg-canvas
                    text-xs font-medium text-content-muted"
             x-text="`+${overflow}`"></div>
    </div>
</div>
