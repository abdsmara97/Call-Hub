@php
    use App\Enums\FormFieldType;

    $closed = $form->isClosed();
    $rooms = $form->postings->pluck('room')->filter();
@endphp

<div>
    <div class="mx-auto max-w-4xl px-4 py-6">
        <a href="{{ route('admin.forms') }}" wire:navigate
           class="mb-4 inline-flex items-center gap-1 text-xs text-content-muted hover:text-content">
            <x-icon name="chevron-left" class="h-3.5 w-3.5" />
            All forms
        </a>

        <x-panel-heading :title="$form->title">
            <p class="mt-1 text-sm text-content-muted">
                {{ $responses->count() }} {{ Str::plural('response', $responses->count()) }}
                @if ($rooms->isNotEmpty())
                    from {{ $form->audience()->count() }} {{ Str::plural('person', $form->audience()->count()) }}
                    across {{ $rooms->count() }} {{ Str::plural('conversation', $rooms->count()) }}
                @endif
            </p>

            @if ($rooms->isNotEmpty())
                <p class="mt-1.5 flex flex-wrap items-center gap-1.5 text-2xs text-content-muted">
                    <span class="text-content-subtle">Sent to</span>
                    @foreach ($rooms as $room)
                        <a href="{{ route('rooms.show', $room) }}" wire:navigate class="badge-neutral hover:underline">
                            {{ $room->isDm() ? 'Direct message' : $room->displayNameFor(auth()->user()) }}
                        </a>
                    @endforeach
                </p>
            @else
                <p class="mt-1.5 text-2xs text-content-muted">
                    This form has not been sent anywhere yet. Choose it from a conversation's composer
                    to put it in front of people.
                </p>
            @endif

            <div class="mt-3 flex flex-wrap items-center gap-2">
                @if ($closed)
                    <span class="badge-neutral">
                        <x-icon name="lock" class="h-3 w-3" />
                        Closed {{ $form->closes_at?->diffForHumans() }}
                    </span>
                @else
                    @can('close', $form)
                        <button type="button" wire:click="closeForm"
                                wire:confirm="Close this form? Nobody can add or change an answer afterwards, and it cannot be reopened."
                                class="btn-secondary !py-1.5 !text-xs">
                            <x-icon name="lock" class="h-3.5 w-3.5" />
                            Close the form
                        </button>
                    @endcan
                @endif
            </div>
        </x-panel-heading>

        <div role="status" aria-live="polite">
            @if ($status)
                <p class="mb-4 flex items-start gap-2 rounded-md bg-brand-tint px-3 py-2 text-sm font-medium text-brand-text">
                    <x-icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>{{ $status }}</span>
                </p>
            @endif
        </div>

        @if ($outstanding->isNotEmpty())
            <div class="panel mb-5 p-4">
                <h2 class="text-sm font-semibold">
                    Still to answer
                    <span class="ml-1 text-2xs font-normal text-content-subtle">{{ $outstanding->count() }}</span>
                </h2>
                <ul class="mt-2 flex flex-wrap gap-1.5">
                    @foreach ($outstanding as $person)
                        <li class="badge-neutral">{{ $person->name }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($responses->isEmpty())
            <div class="panel flex flex-col items-center gap-3 px-6 py-14 text-center">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-surface-sunken">
                    <x-icon name="document" class="h-6 w-6 text-content-subtle" />
                </span>
                <h2 class="text-lg font-semibold">No answers yet</h2>
                <p class="max-w-md text-sm text-content-muted">
                    @if ($rooms->isEmpty())
                        Nobody has been asked yet — the form has not been sent into a conversation.
                    @else
                        Nobody has filled this in. They will find it in the conversation, as the
                        message that announced it.
                    @endif
                </p>
            </div>
        @else
            <ul role="list" class="space-y-3">
                @foreach ($responses as $entry)
                    <li class="panel p-4" wire:key="response-{{ $entry->id }}">
                        <div class="flex items-start gap-3">
                            @if ($entry->user)
                                <x-avatar :user="$entry->user" size="md" />
                            @endif

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-baseline gap-x-2">
                                    <span class="text-sm font-semibold">
                                        {{ $entry->user?->name ?? 'Former employee' }}
                                    </span>
                                    @if ($entry->user?->job_title)
                                        <span class="text-2xs text-content-subtle">{{ $entry->user->job_title }}</span>
                                    @endif
                                    <time datetime="{{ $entry->submitted_at?->toIso8601String() }}"
                                          title="{{ $entry->submitted_at?->toDayDateTimeString() }}"
                                          class="text-2xs text-content-subtle">
                                        {{ $entry->submitted_at?->diffForHumans() }}
                                    </time>
                                </div>

                                <dl class="mt-3 space-y-3">
                                    @foreach ($form->fields as $field)
                                        @php $answer = $entry->answerTo($field); @endphp

                                        <div wire:key="answer-{{ $entry->id }}-{{ $field->id }}">
                                            <dt class="text-2xs font-medium uppercase tracking-wide text-content-subtle">
                                                {{ $field->label }}
                                            </dt>

                                            <dd class="mt-0.5 text-sm">
                                                @if (! $answer || $answer->isBlank())
                                                    <span class="text-content-subtle">Left blank</span>
                                                @elseif ($field->type === FormFieldType::Picture)
                                                    <ul class="flex flex-wrap gap-2">
                                                        @foreach ($answer->files as $file)
                                                            <li wire:key="rfile-{{ $file->id }}">
                                                                <a href="{{ $file->temporaryUrl() }}"
                                                                   target="_blank" rel="noopener noreferrer"
                                                                   title="{{ $file->original_name }} · {{ $file->humanSize() }}">
                                                                    <img src="{{ $file->temporaryUrl() }}"
                                                                         alt="{{ $file->original_name }}"
                                                                         class="h-24 w-24 rounded-md border border-line object-cover">
                                                                </a>
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                @elseif ($field->type === FormFieldType::LongText)
                                                    <p class="whitespace-pre-line">{{ $answer->display() }}</p>
                                                @else
                                                    {{ $answer->display() }}
                                                @endif
                                            </dd>
                                        </div>
                                    @endforeach
                                </dl>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
