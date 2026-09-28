<div>
    <div class="mx-auto max-w-3xl px-4 py-6">
        <a href="{{ route('admin.forms') }}" wire:navigate
           class="mb-4 inline-flex items-center gap-1 text-xs text-content-muted hover:text-content">
            <x-icon name="chevron-left" class="h-3.5 w-3.5" />
            All forms
        </a>

        <x-panel-heading title="New form"
                         description="Write the questions once. You send the form to people afterwards, by choosing it from a conversation. Answers are attributable — people's names are recorded against what they write." />

        <form wire:submit="create" class="space-y-5">
            <div class="panel space-y-4 p-4">
                <div>
                    <x-input-label for="form-title" value="Title" />
                    <input id="form-title" type="text" wire:model="title" class="field mt-1"
                           placeholder="Site safety check" required>
                    <x-input-error :messages="$errors->get('title')" class="mt-1.5" />
                    <p class="mt-1 text-2xs text-content-subtle">
                        People see this in the forms list, and it becomes the message that announces
                        the form when you send it into a conversation.
                    </p>
                </div>

                <div>
                    <x-input-label for="form-description" value="Description (optional)" />
                    <textarea id="form-description" wire:model="description" rows="2" class="field mt-1"
                              placeholder="Why you are asking, and anything people need before they start."></textarea>
                    <x-input-error :messages="$errors->get('description')" class="mt-1.5" />
                </div>

                <div>
                    <x-input-label for="form-closes" value="Close automatically (optional)" />
                    <input id="form-closes" type="datetime-local" wire:model="closesAt" class="field mt-1 max-w-xs">
                    <x-input-error :messages="$errors->get('closesAt')" class="mt-1.5" />
                    <p class="mt-1 text-2xs text-content-subtle">
                        Leave this empty to keep the form open until you close it yourself.
                    </p>
                </div>
            </div>

            <div>
                <div class="mb-2 flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold">Questions</h2>
                    <span class="text-2xs text-content-subtle">
                        {{ count($fields) }} of {{ \App\Services\FormService::MAX_FIELDS }}
                    </span>
                </div>

                <ul class="space-y-3">
                    @foreach ($fields as $index => $field)
                        <li class="panel p-4" wire:key="field-{{ $index }}">
                            <div class="flex items-start gap-3">
                                <span class="mt-2 flex h-6 w-6 shrink-0 items-center justify-center rounded-full
                                             bg-surface-sunken text-2xs font-semibold text-content-subtle"
                                      aria-hidden="true">{{ $index + 1 }}</span>

                                <div class="min-w-0 flex-1 space-y-3">
                                    <div>
                                        <x-input-label :for="'field-label-'.$index" value="Question" class="sr-only" />
                                        <input id="field-label-{{ $index }}" type="text"
                                               wire:model="fields.{{ $index }}.label" class="field"
                                               placeholder="What did you inspect?">
                                        <x-input-error :messages="$errors->get('fields.'.$index.'.label')" class="mt-1.5" />
                                    </div>

                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <x-input-label :for="'field-type-'.$index" value="Answer type" />
                                            <select id="field-type-{{ $index }}"
                                                    wire:model.live="fields.{{ $index }}.type" class="field mt-1">
                                                @foreach ($types as $type)
                                                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                                @endforeach
                                            </select>
                                            <p class="mt-1 text-2xs text-content-subtle">
                                                {{ \App\Enums\FormFieldType::from($field['type'])->hint() }}
                                            </p>
                                        </div>

                                        <div>
                                            <x-input-label :for="'field-help-'.$index" value="Hint (optional)" />
                                            <input id="field-help-{{ $index }}" type="text"
                                                   wire:model="fields.{{ $index }}.help" class="field mt-1"
                                                   placeholder="Shown under the question">
                                            <x-input-error :messages="$errors->get('fields.'.$index.'.help')" class="mt-1.5" />
                                        </div>
                                    </div>

                                    <label class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" wire:model="fields.{{ $index }}.required"
                                               class="h-4 w-4 rounded border-line text-brand focus:ring-brand/30">
                                        Required — people cannot submit without answering this
                                    </label>
                                </div>

                                <div class="flex shrink-0 flex-col gap-1">
                                    <button type="button" wire:click="moveField({{ $index }}, -1)"
                                            class="btn-ghost !px-1.5 !py-1" title="Move up"
                                            @disabled($index === 0)>
                                        <x-icon name="chevron-down" class="h-4 w-4 rotate-180" />
                                        <span class="sr-only">Move question {{ $index + 1 }} up</span>
                                    </button>

                                    <button type="button" wire:click="moveField({{ $index }}, 1)"
                                            class="btn-ghost !px-1.5 !py-1" title="Move down"
                                            @disabled($index === count($fields) - 1)>
                                        <x-icon name="chevron-down" class="h-4 w-4" />
                                        <span class="sr-only">Move question {{ $index + 1 }} down</span>
                                    </button>

                                    <button type="button" wire:click="removeField({{ $index }})"
                                            class="btn-ghost !px-1.5 !py-1" title="Remove question"
                                            @disabled(count($fields) <= 1)>
                                        <x-icon name="trash" class="h-4 w-4" />
                                        <span class="sr-only">Remove question {{ $index + 1 }}</span>
                                    </button>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <x-input-error :messages="$errors->get('fields')" class="mt-2" />

                <button type="button" wire:click="addField" class="btn-secondary mt-3">
                    <x-icon name="plus" class="h-4 w-4" />
                    Add a question
                </button>
            </div>

            <div class="flex items-center gap-2">
                <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="create">
                    <x-icon name="check" class="h-4 w-4" />
                    Save the form
                </button>

                <a href="{{ route('admin.forms') }}" wire:navigate class="btn-ghost">Cancel</a>

                <span wire:loading wire:target="create" class="text-xs text-content-muted">Saving…</span>
            </div>
        </form>
    </div>
</div>
