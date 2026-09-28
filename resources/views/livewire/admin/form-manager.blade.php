<div>
    <div class="mx-auto max-w-5xl px-4 py-6">
        @include('partials.admin-nav')

        <x-panel-heading title="Forms"
                         description="Questions to put to staff. Write one here, then send it to people by choosing it from a conversation — a room or a direct message." />

        <div role="status" aria-live="polite">
            @if ($status || session('status'))
                <p class="mb-4 flex items-start gap-2 rounded-md bg-brand-tint px-3 py-2 text-sm font-medium text-brand-text">
                    <x-icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>{{ $status ?: session('status') }}</span>
                </p>
            @endif
        </div>

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <a href="{{ route('admin.forms.create') }}" wire:navigate class="btn-primary">
                <x-icon name="plus" class="h-4 w-4" />
                New form
            </a>

            <div class="ml-auto flex items-center gap-1" role="group" aria-label="Filter forms">
                @foreach (['open' => 'Open', 'closed' => 'Closed', 'all' => 'All'] as $value => $label)
                    <button type="button" wire:click="$set('filter', '{{ $value }}')"
                            class="{{ $filter === $value ? 'btn-secondary' : 'btn-ghost' }} !py-1.5 !text-xs"
                            aria-pressed="{{ $filter === $value ? 'true' : 'false' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        @if ($forms->isEmpty())
            <div class="panel flex flex-col items-center gap-3 px-6 py-14 text-center">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-surface-sunken">
                    <x-icon name="document" class="h-6 w-6 text-content-subtle" />
                </span>
                <h2 class="text-lg font-semibold">
                    {{ $filter === 'open' ? 'No open forms' : 'No forms here' }}
                </h2>
                <p class="max-w-md text-sm text-content-muted">
                    A form is a set of questions — short text, long text, a number, yes or no, or
                    pictures — that you put to staff in a conversation. Answers are recorded against
                    the person's name and only you and other administrators can read them.
                </p>
                <a href="{{ route('admin.forms.create') }}" wire:navigate class="btn-primary mt-1">
                    <x-icon name="plus" class="h-4 w-4" />
                    Write the first one
                </a>
            </div>
        @else
            <ul role="list" class="space-y-3">
                @foreach ($forms as $form)
                    @php $closed = $form->isClosed(); @endphp

                    <li class="panel p-4" wire:key="form-{{ $form->id }}">
                        <div class="flex flex-wrap items-start gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                    <h2 class="text-sm font-semibold">{{ $form->title }}</h2>

                                    @if ($closed)
                                        <span class="badge-neutral">
                                            <x-icon name="lock" class="h-3 w-3" />
                                            Closed
                                        </span>
                                    @elseif ($form->closes_at)
                                        <span class="badge-neutral">
                                            <x-icon name="clock" class="h-3 w-3" />
                                            Closes {{ $form->closes_at->diffForHumans() }}
                                        </span>
                                    @endif

                                    @unless ($form->postings->isNotEmpty())
                                        <span class="badge-neutral">Not sent yet</span>
                                    @endunless
                                </div>

                                @if ($form->description)
                                    <p class="mt-0.5 line-clamp-2 text-xs text-content-muted">{{ $form->description }}</p>
                                @endif

                                <p class="mt-1 text-2xs text-content-subtle">
                                    {{ $form->fields_count }} {{ Str::plural('question', $form->fields_count) }}
                                    · {{ $form->response_count }} {{ Str::plural('response', $form->response_count) }}
                                    · by {{ $form->creator?->name ?? 'a former employee' }}
                                    {{ $form->created_at?->diffForHumans() }}
                                </p>

                                @if ($form->postings->isNotEmpty())
                                    <p class="mt-1.5 flex flex-wrap items-center gap-1.5 text-2xs text-content-muted">
                                        <span class="text-content-subtle">Sent to</span>
                                        @foreach ($form->postings as $posting)
                                            @if ($posting->room)
                                                <a href="{{ route('rooms.show', $posting->room) }}" wire:navigate
                                                   class="badge-neutral hover:underline">
                                                    {{ $posting->room->isDm()
                                                        ? 'Direct message'
                                                        : $posting->room->displayNameFor(auth()->user()) }}
                                                </a>
                                            @endif
                                        @endforeach
                                    </p>
                                @endif
                            </div>

                            <div class="flex shrink-0 flex-wrap items-center gap-2">
                                <a href="{{ route('admin.forms.responses', $form) }}" wire:navigate
                                   class="btn-secondary !py-1.5 !text-xs">
                                    <x-icon name="users" class="h-3.5 w-3.5" />
                                    Responses
                                </a>

                                <a href="{{ route('forms.show', $form) }}" wire:navigate
                                   class="btn-ghost !py-1.5 !text-xs" title="See the form as staff see it">
                                    <x-icon name="search" class="h-3.5 w-3.5" />
                                    Preview
                                </a>

                                @can('close', $form)
                                    <button type="button" wire:click="closeForm({{ $form->id }})"
                                            wire:confirm="Close “{{ $form->title }}”? Nobody can add or change an answer afterwards, and it cannot be reopened."
                                            class="btn-ghost !py-1.5 !text-xs">
                                        <x-icon name="lock" class="h-3.5 w-3.5" />
                                        Close
                                    </button>
                                @endcan

                                @can('delete', $form)
                                    <button type="button" wire:click="deleteForm({{ $form->id }})"
                                            wire:confirm="Delete “{{ $form->title }}”? This removes the form and any message announcing it."
                                            class="btn-ghost !py-1.5 !text-xs">
                                        <x-icon name="trash" class="h-3.5 w-3.5" />
                                        Delete
                                    </button>
                                @endcan
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="mt-6">
                {{ $forms->onEachSide(1)->links() }}
            </div>
        @endif
    </div>
</div>
