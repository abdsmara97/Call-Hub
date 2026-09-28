@php
    $me = auth()->user();
    $closed = $form->isClosed();
    $mine = $form->responseFor($me);
    $answered = $mine?->isSubmitted() ?? false;
    $canRespond = $me->can('respond', $form);
    $canViewResponses = $me->can('viewResponses', $form);
    $count = $form->responseCount();
@endphp

<section class="mt-2 rounded-md border border-line bg-surface-sunken p-3"
         aria-label="Form: {{ $form->title }}">

    <div class="flex items-start gap-2">
        <x-icon name="document" class="mt-0.5 h-4 w-4 shrink-0 text-content-subtle" />

        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold">{{ $form->title }}</p>

            @if ($form->description)
                <p class="mt-0.5 line-clamp-2 text-xs text-content-muted">{{ $form->description }}</p>
            @endif

            <p class="mt-0.5 text-2xs text-content-muted">
                {{ $form->fields->count() }} {{ Str::plural('question', $form->fields->count()) }}
                @if ($canViewResponses)
                    · {{ $count }} {{ Str::plural('response', $count) }}
                @endif
                @if ($closed)
                    · <span class="font-medium">Closed</span>
                @elseif ($form->closes_at)
                    · closes {{ $form->closes_at->diffForHumans() }}
                @endif
            </p>
        </div>

        @if ($answered)
            <span class="badge-brand shrink-0">
                <x-icon name="check-circle" class="h-3 w-3" />
                Answered
            </span>
        @endif
    </div>

    <div class="mt-2.5 flex flex-wrap items-center gap-2">
        {{-- Filling one in is a screen of its own, not something to squeeze into
             a timeline row: a form can be twenty questions and a file upload. --}}
        <a href="{{ route('forms.show', $form) }}" wire:navigate class="btn-secondary !py-1.5 !text-xs">
            <x-icon name="pencil" class="h-3.5 w-3.5" />
            @if ($answered)
                {{ $closed ? 'See what you sent' : 'Change my answers' }}
            @elseif ($canRespond)
                Fill this in
            @else
                Open the form
            @endif
        </a>

        @if ($canViewResponses)
            <a href="{{ route('admin.forms.responses', $form) }}" wire:navigate class="btn-ghost !py-1.5 !text-xs">
                <x-icon name="users" class="h-3.5 w-3.5" />
                Responses
            </a>
        @endif

        @if (! $closed && ! $canRespond && ! $canViewResponses)
            <span class="text-2xs text-content-muted">Join this room to answer.</span>
        @endif
    </div>
</section>
