@php
    $me = auth()->user();
    $recipients = $emergency->recipients;
    $acknowledged = $recipients->filter->hasAcknowledged();
    $pending = $recipients->reject->hasAcknowledged();
    $mine = $recipients->firstWhere('user_id', $me->id);
    $iMustAck = $mine && ! $mine->hasAcknowledged();
@endphp

{{--
    Pinned to the top of the conversation until it is resolved. Rendered with a
    solid emergency header, an icon, and the literal word EMERGENCY so the
    meaning survives both greyscale and red/green colour blindness.
--}}
<section class="overflow-hidden rounded-lg border-2 border-emergency shadow-emergency
                {{ $iMustAck ? 'animate-emergency-pulse' : '' }}"
         aria-labelledby="emergency-{{ $emergency->id }}-title">

    <header class="flex flex-wrap items-center gap-2 bg-emergency-solid px-3 py-2 text-emergency-on">
        <x-icon name="alert" class="h-5 w-5 shrink-0" />
        <h3 id="emergency-{{ $emergency->id }}-title" class="text-sm font-bold uppercase tracking-wide">
            Emergency
        </h3>
        <span class="text-xs opacity-90">
            from {{ $emergency->sender->name }} ·
            <time datetime="{{ $emergency->created_at->toIso8601String() }}">
                {{ $emergency->created_at->diffForHumans() }}
            </time>
        </span>

        @if ($emergency->escalation_count > 0)
            <span class="ml-auto rounded-full bg-black/25 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide">
                Re-alerted &times;{{ $emergency->escalation_count }}
            </span>
        @endif
    </header>

    <div class="bg-emergency-tint px-3 py-3">
        <p class="whitespace-pre-wrap break-words text-base font-medium text-content">{{ $emergency->body }}</p>

        <div class="mt-3 flex flex-wrap items-center gap-2">
            @if ($iMustAck)
                <button type="button" wire:click="acknowledge({{ $emergency->id }})"
                        class="btn-emergency">
                    <x-icon name="check" class="h-4 w-4" />
                    Acknowledge
                </button>
                <p class="text-xs text-content-muted">
                    You have not acknowledged this yet. You will keep being alerted until you do.
                </p>
            @elseif ($mine)
                <span class="badge bg-brand-tint-strong text-brand-text">
                    <x-icon name="check-circle" class="h-3.5 w-3.5" />
                    You acknowledged
                    <time datetime="{{ $mine->acknowledged_at->toIso8601String() }}">
                        {{ $mine->acknowledged_at->diffForHumans() }}
                    </time>
                </span>
            @endif

            @can('resolve', $emergency)
                <button type="button" wire:click="resolveEmergency({{ $emergency->id }})"
                        wire:confirm="Mark this emergency resolved? Re-alerts will stop."
                        class="btn-secondary !py-1.5 !text-xs">
                    Mark resolved
                </button>
            @endcan
        </div>

        {{-- Live acknowledgement roll-call. --}}
        <div class="mt-3 border-t border-emergency-border pt-2">
            <p class="mb-1.5 text-2xs font-semibold uppercase tracking-wide text-content-muted">
                Acknowledged {{ $acknowledged->count() }} of {{ $recipients->count() }}
            </p>

            <div class="flex flex-wrap gap-1.5" role="list" aria-label="Acknowledgement status by person">
                @foreach ($acknowledged as $recipient)
                    <span role="listitem"
                          class="inline-flex items-center gap-1 rounded-full bg-brand-tint-strong px-2 py-0.5 text-2xs font-medium text-brand-text"
                          title="Acknowledged {{ $recipient->acknowledged_at->toDayDateTimeString() }}">
                        <x-icon name="check" class="h-3 w-3" />
                        {{ $recipient->user->name }}
                    </span>
                @endforeach

                @foreach ($pending as $recipient)
                    <span role="listitem"
                          class="inline-flex items-center gap-1 rounded-full border border-emergency-border
                                 bg-surface px-2 py-0.5 text-2xs font-medium text-emergency-text"
                          title="Has not acknowledged">
                        <x-icon name="clock" class="h-3 w-3" />
                        {{ $recipient->user->name }}
                    </span>
                @endforeach

                @if ($recipients->isEmpty())
                    <span class="text-2xs text-content-muted">No other recipients in this conversation.</span>
                @endif
            </div>
        </div>
    </div>
</section>
