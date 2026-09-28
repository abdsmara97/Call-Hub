@php
    use App\Enums\FormFieldType;

    $closed = $form->isClosed();
    $submitted = $response?->isSubmitted() ?? false;
@endphp

<div>
    <div class="mx-auto max-w-2xl px-4 py-6">
        @if ($room)
            <a href="{{ route('rooms.show', $room) }}" wire:navigate
               class="mb-4 inline-flex items-center gap-1 text-xs text-content-muted hover:text-content">
                <x-icon name="chevron-left" class="h-3.5 w-3.5" />
                Back to {{ $room->displayNameFor(auth()->user()) }}
            </a>
        @elseif ($canViewResponses)
            <a href="{{ route('admin.forms') }}" wire:navigate
               class="mb-4 inline-flex items-center gap-1 text-xs text-content-muted hover:text-content">
                <x-icon name="chevron-left" class="h-3.5 w-3.5" />
                All forms
            </a>
        @endif

        <x-panel-heading :title="$form->title">
            <p class="mt-1 text-sm text-content-muted">
                Asked by {{ $form->creator?->name ?? 'a former employee' }}
                · {{ $form->created_at?->diffForHumans() }}
            </p>

            @if ($form->description)
                <p class="mt-3 whitespace-pre-line text-sm text-content">{{ $form->description }}</p>
            @endif

            <div class="mt-3 flex flex-wrap items-center gap-2">
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

                @if ($submitted)
                    <span class="badge-brand">
                        <x-icon name="check-circle" class="h-3 w-3" />
                        You answered {{ $response->submitted_at?->diffForHumans() }}
                    </span>
                @endif

                @if ($canViewResponses)
                    <a href="{{ route('admin.forms.responses', $form) }}" wire:navigate class="btn-ghost !py-1 !text-xs">
                        <x-icon name="users" class="h-3.5 w-3.5" />
                        {{ $form->responseCount() }} {{ Str::plural('response', $form->responseCount()) }}
                    </a>
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

        {{-- Who can see this. Said before the questions, not after: it changes
             what people are willing to write. --}}
        <p class="mb-4 flex items-start gap-2 rounded-md bg-surface-sunken px-3 py-2 text-2xs text-content-muted">
            <x-icon name="lock" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
            <span>
                Your answers are recorded against your name and can be read by
                {{ $form->creator?->name ?? 'the person who wrote this' }} and by administrators.
                @if ($room)
                    Other people in {{ $room->displayNameFor(auth()->user()) }} cannot see them.
                @else
                    Nobody else can see them.
                @endif
            </span>
        </p>

        @if (! $canRespond)
            <div class="panel mb-4 flex items-start gap-2 p-4 text-sm">
                <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0 text-content-subtle" />
                <p class="text-content-muted">
                    @if ($closed)
                        This form is closed, so answers can no longer be added or changed.
                        @if ($submitted) What you sent is below. @endif
                    @elseif ($room)
                        You need to be a member of {{ $room->displayNameFor(auth()->user()) }} to answer this form.
                    @else
                        This form has not been sent to anyone yet, so there is nothing to answer.
                        You are seeing it because you administer it.
                    @endif
                </p>
            </div>
        @endif

        <form wire:submit="submit" class="space-y-4">
            <x-input-error :messages="$errors->get('form')" />

            @foreach ($form->fields as $field)
                @php
                    $answer = $response?->answerTo($field);
                    $inputId = 'field-'.$field->id;
                @endphp

                <div class="panel p-4" wire:key="fill-{{ $field->id }}">
                    <x-input-label :for="$inputId">
                        {{ $field->label }}
                        @if ($field->required)
                            <span class="text-emergency" title="Required" aria-label="required">*</span>
                        @endif
                    </x-input-label>

                    @if ($field->help)
                        <p class="mt-0.5 text-2xs text-content-subtle">{{ $field->help }}</p>
                    @endif

                    <div class="mt-2">
                        @switch($field->type)
                            @case(FormFieldType::LongText)
                                <textarea id="{{ $inputId }}" rows="4" class="field"
                                          wire:model="values.{{ $field->id }}"
                                          @disabled(! $canRespond)></textarea>
                                @break

                            @case(FormFieldType::Number)
                                <input id="{{ $inputId }}" type="number" step="any" class="field max-w-xs"
                                       wire:model="values.{{ $field->id }}"
                                       @disabled(! $canRespond)>
                                @break

                            @case(FormFieldType::YesNo)
                                <div class="flex gap-2" role="group" aria-labelledby="{{ $inputId }}">
                                    @foreach (['yes' => 'Yes', 'no' => 'No'] as $value => $label)
                                        <label class="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-1.5 text-sm
                                                      {{ ($values[$field->id] ?? null) === $value
                                                          ? 'border-brand bg-brand-tint font-medium'
                                                          : 'border-line bg-surface' }}
                                                      {{ $canRespond ? 'hover:border-brand-border' : 'cursor-default opacity-70' }}">
                                            <input type="radio" value="{{ $value }}"
                                                   wire:model.live="values.{{ $field->id }}"
                                                   name="field-{{ $field->id }}"
                                                   class="h-4 w-4 border-line text-brand focus:ring-brand/30"
                                                   @disabled(! $canRespond)>
                                            {{ $label }}
                                        </label>
                                    @endforeach
                                </div>
                                @break

                            @case(FormFieldType::Picture)
                                @if ($answer && $answer->files->isNotEmpty())
                                    <ul class="mb-2 flex flex-wrap gap-2">
                                        @foreach ($answer->files as $file)
                                            <li class="relative" wire:key="file-{{ $file->id }}">
                                                <a href="{{ $file->temporaryUrl() }}" target="_blank" rel="noopener noreferrer">
                                                    <img src="{{ $file->temporaryUrl() }}"
                                                         alt="{{ $file->original_name }}"
                                                         class="h-24 w-24 rounded-md border border-line object-cover">
                                                </a>

                                                @if ($canRespond)
                                                    <button type="button"
                                                            wire:click="removePicture({{ $file->id }})"
                                                            class="absolute -right-1.5 -top-1.5 rounded-full border border-line
                                                                   bg-surface p-1 shadow-sm hover:bg-surface-sunken"
                                                            title="Remove this picture">
                                                        <x-icon name="x" class="h-3 w-3" />
                                                        <span class="sr-only">Remove {{ $file->original_name }}</span>
                                                    </button>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                @if ($canRespond)
                                    <input id="{{ $inputId }}" type="file" multiple
                                           accept="image/jpeg,image/png,image/gif,image/webp"
                                           wire:model="uploads.{{ $field->id }}" class="field !py-1.5 text-sm">

                                    <p class="mt-1 text-2xs text-content-subtle">
                                        Up to {{ FormFieldType::MAX_PICTURES }} images.
                                        @if ($answer && $answer->files->isNotEmpty())
                                            Choosing new ones replaces what is already here.
                                        @endif
                                    </p>

                                    <div wire:loading wire:target="uploads.{{ $field->id }}"
                                         class="mt-1 text-2xs text-content-muted">Uploading…</div>
                                @endif

                                <x-input-error :messages="$errors->get('uploads.'.$field->id)" class="mt-1.5" />
                                <x-input-error :messages="$errors->get('uploads.'.$field->id.'.*')" class="mt-1.5" />
                                @break

                            @default
                                <input id="{{ $inputId }}" type="text" class="field"
                                       wire:model="values.{{ $field->id }}"
                                       maxlength="{{ $field->type->maxLength() }}"
                                       @disabled(! $canRespond)>
                        @endswitch

                        @unless ($field->type === FormFieldType::Picture)
                            <x-input-error :messages="$errors->get('values.'.$field->id)" class="mt-1.5" />
                        @endunless
                    </div>
                </div>
            @endforeach

            @if ($canRespond)
                <div class="flex items-center gap-2">
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="submit">
                        <x-icon name="check" class="h-4 w-4" />
                        {{ $submitted ? 'Update my answers' : 'Submit' }}
                    </button>

                    @if ($room)
                        <a href="{{ route('rooms.show', $room) }}" wire:navigate class="btn-ghost">Back</a>
                    @endif

                    <span wire:loading wire:target="submit" class="text-xs text-content-muted">Saving…</span>
                </div>
            @endif
        </form>
    </div>
</div>
