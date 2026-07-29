@php
    $me = auth()->user();
    $chosen = $poll->chosenOptionIdFor($me);
    $tally = $poll->tally();
    $total = $poll->totalVotes();
    $closed = $poll->isClosed();
    $canVote = $me->can('vote', $poll);
@endphp

<section class="mt-2 rounded-md border border-line bg-surface-sunken p-3"
         aria-label="Poll: {{ $poll->question }}">

    <div class="flex items-start gap-2">
        <x-icon name="chart" class="mt-0.5 h-4 w-4 shrink-0 text-content-subtle" />
        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold">{{ $poll->question }}</p>
            <p class="text-2xs text-content-muted">
                {{ $total }} {{ Str::plural('vote', $total) }}
                @if ($closed)
                    · <span class="font-medium">Closed</span>
                @elseif ($poll->closes_at)
                    · closes {{ $poll->closes_at->diffForHumans() }}
                @endif
            </p>
        </div>

        @can('close', $poll)
            <button type="button" wire:click="closePoll({{ $poll->id }})"
                    wire:confirm="Close this poll? Voting stops for everyone and cannot be reopened."
                    class="btn-ghost !px-1.5 !py-1 !text-2xs">Close</button>
        @endcan
    </div>

    {{-- Each option is one button: its own bar, count and share. Voting for the
         option you already hold retracts it. --}}
    <ul class="mt-2.5 space-y-1.5">
        @foreach ($poll->options as $option)
            @php
                $count = $tally[$option->id] ?? 0;
                $share = $poll->shareOf($option);
                $isMine = $chosen === $option->id;
            @endphp

            <li>
                <button type="button"
                        @if ($canVote) wire:click="vote({{ $poll->id }}, {{ $option->id }})" @else disabled @endif
                        class="relative block w-full overflow-hidden rounded-md border px-2.5 py-1.5 text-left
                               transition-colors duration-fast
                               {{ $isMine ? 'border-brand bg-brand-tint' : 'border-line bg-surface' }}
                               {{ $canVote ? 'hover:border-brand-border' : 'cursor-default' }}"
                        aria-pressed="{{ $isMine ? 'true' : 'false' }}"
                        @if ($canVote)
                            aria-label="{{ $isMine
                                ? 'Retract your vote for '.$option->label
                                : 'Vote for '.$option->label }}, currently {{ $count }} of {{ $total }}"
                        @endif>

                    {{-- Bar sits behind the label; the number beside it carries the
                         same information for anyone who cannot see the fill. --}}
                    <span class="absolute inset-y-0 left-0 bg-brand/15 transition-all duration-normal"
                          style="width: {{ $share }}%" aria-hidden="true"></span>

                    <span class="relative flex items-center gap-2">
                        @if ($isMine)
                            <x-icon name="check-circle" class="h-3.5 w-3.5 shrink-0 text-brand-text" />
                        @endif
                        <span class="min-w-0 flex-1 truncate text-sm {{ $isMine ? 'font-medium' : '' }}">
                            {{ $option->label }}
                        </span>
                        <span class="shrink-0 text-2xs tabular-nums text-content-muted">
                            {{ $count }} · {{ $share }}%
                        </span>
                    </span>
                </button>
            </li>
        @endforeach
    </ul>

    @if (! $closed && ! $canVote)
        <p class="mt-2 text-2xs text-content-muted">Join this room to vote.</p>
    @endif

    <x-input-error :messages="$errors->get('poll')" class="mt-2" />
</section>
