@php
    $pending = $this->pending;
    $broadcasts = $pending->filter->isBroadcast();
    $direct = $pending->reject->isBroadcast();
@endphp

<div>
    {{--
        Full-screen takeover for administrator broadcasts. Deliberately blocking:
        a company-wide emergency should not be something you scroll past.
    --}}
    @if ($broadcasts->isNotEmpty())
        @php $lead = $broadcasts->first(); @endphp

        <div class="fixed inset-0 z-emergency flex items-center justify-center bg-black/70 p-4"
             role="alertdialog" aria-modal="true"
             aria-labelledby="broadcast-title" aria-describedby="broadcast-body"
             x-data
             x-init="$nextTick(() => $refs.ackButton?.focus())"
             {{-- Keyboard focus is kept inside the dialog: there is nothing
                  useful to tab to behind a company-wide emergency. --}}
             @keydown.tab.prevent="$refs.ackButton?.focus()">

            <div class="w-full max-w-lg overflow-hidden rounded-xl border-2 border-emergency bg-surface shadow-lg">
                <header class="flex items-center gap-3 bg-emergency-solid px-5 py-4 text-emergency-on">
                    <x-icon name="alert" class="h-7 w-7 shrink-0" />
                    <div>
                        <h2 id="broadcast-title" class="text-lg font-bold uppercase tracking-wide">
                            Emergency broadcast
                        </h2>
                        <p class="text-xs opacity-90">
                            {{ $lead->sender->name }} · {{ $lead->targetLabel() }} ·
                            <time datetime="{{ $lead->created_at->toIso8601String() }}">
                                {{ $lead->created_at->diffForHumans() }}
                            </time>
                        </p>
                    </div>
                </header>

                <div class="px-5 py-5">
                    <p id="broadcast-body" class="whitespace-pre-wrap break-words text-base font-medium">{{ $lead->body }}</p>

                    @if ($broadcasts->count() > 1)
                        <p class="mt-3 text-xs text-content-muted">
                            {{ $broadcasts->count() - 1 }} more emergency
                            {{ Str::plural('broadcast', $broadcasts->count() - 1) }} waiting behind this one.
                        </p>
                    @endif

                    <div class="mt-5 flex flex-wrap gap-2">
                        <button type="button" x-ref="ackButton"
                                wire:click="acknowledge({{ $lead->id }})"
                                class="btn-emergency">
                            <x-icon name="check" class="h-4 w-4" />
                            I have read this
                        </button>

                        @if ($broadcasts->count() > 1)
                            <button type="button" wire:click="acknowledgeAll" class="btn-secondary">
                                Acknowledge all {{ $pending->count() }}
                            </button>
                        @endif
                    </div>

                    <p class="mt-3 text-2xs text-content-subtle">
                        Your acknowledgement, with the time, is recorded in the emergency log.
                    </p>
                </div>
            </div>
        </div>
    @endif

    {{--
        Room and DM emergencies: a stacked banner that stays until acknowledged
        but does not block the rest of the interface.
    --}}
    @if ($direct->isNotEmpty())
        <div class="fixed inset-x-0 top-16 z-emergency mx-auto flex w-full max-w-md flex-col gap-2 px-3"
             role="region" aria-label="Unacknowledged emergency messages">

            @foreach ($direct as $emergency)
                <article class="animate-fade-in-up overflow-hidden rounded-lg border-2 border-emergency
                                bg-surface shadow-emergency">
                    <header class="flex items-center gap-2 bg-emergency-solid px-3 py-2 text-emergency-on">
                        <x-icon name="alert" class="h-4 w-4 shrink-0" />
                        <h3 class="text-xs font-bold uppercase tracking-wide">Emergency</h3>
                        <span class="truncate text-2xs opacity-90">{{ $emergency->sender->name }}</span>

                        @if ($emergency->escalation_count > 0)
                            <span class="ml-auto shrink-0 rounded-full bg-black/25 px-1.5 py-0.5 text-2xs font-semibold">
                                Re-alert &times;{{ $emergency->escalation_count }}
                            </span>
                        @endif
                    </header>

                    <div class="px-3 py-2.5">
                        <p class="line-clamp-4 whitespace-pre-wrap break-words text-sm font-medium">{{ $emergency->body }}</p>

                        <div class="mt-2.5 flex flex-wrap gap-2">
                            <button type="button" wire:click="acknowledge({{ $emergency->id }})"
                                    class="btn-emergency !py-1.5 !text-xs">
                                <x-icon name="check" class="h-3.5 w-3.5" />
                                Acknowledge
                            </button>

                            @if ($emergency->room_id)
                                <a href="{{ route('rooms.show', $emergency->room_id) }}" wire:navigate
                                   class="btn-secondary !py-1.5 !text-xs">Open conversation</a>
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    {{--
        Sound, tab-title flash and the screen-reader announcement. Kept in the
        component so it fires wherever the user is, and marked wire:ignore so a
        re-render never restarts the audio.
    --}}
    <div wire:ignore
         x-data="emergencyAlerting()"
         @emergency-alert.window="fire($event.detail)"
         @emergency-cleared.window="stop()"></div>
</div>
