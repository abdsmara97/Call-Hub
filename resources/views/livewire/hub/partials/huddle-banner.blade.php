{{--
    "Huddle in progress — Join".

    Calm on purpose. This appears for every member of the room, which in a
    company-wide system room is several hundred people, most of whom will never
    join. So: one line, brand tint rather than emergency crimson, a dismiss
    control, and no sound at all.

    role="status" with aria-live="polite", never alertdialog — that is the
    accessibility expression of the whole design. A huddle is an announcement,
    not a summons. Compare call-incoming.blade.php, which is correctly the
    opposite.
--}}

<div x-data="huddleBanner({{ $room->getKey() }}, @js(['id' => auth()->id()]), @js($huddleSnapshot ?? null))"
     x-show="visible" x-cloak
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0 -translate-y-1"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-end="opacity-0"
     class="flex shrink-0 items-center gap-3 border-b border-brand-border bg-brand-tint px-4 py-2"
     role="status" aria-live="polite">

    {{-- motion-safe so a reduced-motion user gets a static dot; the count
         carries the information either way. --}}
    <span class="relative flex h-2 w-2 shrink-0" aria-hidden="true">
        <span class="absolute inline-flex h-full w-full rounded-full bg-brand opacity-60
                     motion-safe:animate-ping" x-show="phase === 'live'"></span>
        <span class="relative inline-flex h-2 w-2 rounded-full bg-brand"></span>
    </span>

    <div class="flex shrink-0 -space-x-2" x-show="phase === 'live'">
        <template x-for="person in facepile" :key="person.id">
            <span class="relative inline-flex h-6 w-6 shrink-0">
                <template x-if="person.avatar_url">
                    <img :src="person.avatar_url" alt=""
                         class="h-6 w-6 rounded-full object-cover ring-2 ring-brand-tint" />
                </template>
                <template x-if="! person.avatar_url">
                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-brand
                                 text-[0.625rem] font-semibold text-white ring-2 ring-brand-tint"
                          x-text="person.initials"></span>
                </template>
            </span>
        </template>

        <span x-show="overflow > 0"
              class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-canvas
                     text-[0.625rem] font-semibold text-content-muted ring-2 ring-brand-tint"
              x-text="`+${overflow}`"></span>
    </div>

    <p class="min-w-0 flex-1 truncate text-xs">
        <span class="font-semibold text-brand-text" x-text="headline"></span>
        <span class="text-content-muted" x-text="subline"></span>
    </p>

    <button type="button" @click="join()" :disabled="! canJoin" :title="joinBlockedReason"
            x-show="phase === 'live' && ! iAmIn"
            class="btn btn-primary shrink-0 !py-1 !text-xs disabled:opacity-50">
        <x-icon name="microphone" class="h-3.5 w-3.5" />
        Join
    </button>

    <span x-show="iAmIn" x-cloak class="badge badge-brand shrink-0">You are in this huddle</span>

    <button type="button" @click="dismiss()" x-show="phase === 'live' && ! iAmIn"
            class="btn btn-ghost shrink-0 !px-1.5 !py-1" aria-label="Hide this huddle notice">
        <x-icon name="x" class="h-3.5 w-3.5" />
    </button>
</div>
