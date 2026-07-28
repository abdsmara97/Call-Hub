<div class="mx-auto max-w-3xl px-4 py-6">
    @includeIf('partials.admin-nav')

    <x-panel-heading
        title="Emergency broadcast"
        description="Reaches every active employee in the selected scope at once, with sound, a browser notification, and a required acknowledgement." />

    @if ($sentEmergencyId)
        <div role="status"
             class="mb-5 flex items-start gap-3 rounded-lg border border-brand-border bg-brand-tint px-4 py-3">
            <x-icon name="check-circle" class="mt-0.5 h-5 w-5 shrink-0 text-brand-text" />
            <div class="text-sm">
                <p class="font-semibold text-brand-text">Broadcast sent.</p>
                <p class="text-content-muted">
                    Acknowledgements are being recorded now.
                    <a href="{{ route('admin.emergency-log') }}" class="font-medium text-brand-text hover:underline">
                        Follow it in the emergency log</a>.
                </p>
            </div>
        </div>
    @endif

    <form wire:submit="{{ $confirming ? 'send' : 'review' }}" class="panel space-y-5 p-5">
        <fieldset>
            <legend class="label">Who should this reach?</legend>

            <div class="space-y-2">
                @foreach ([
                    'company' => ['One company', 'Everyone employed by a single company.'],
                    'administration' => ['One administration', 'Everyone in a single department or directorate.'],
                    'all' => ['Everyone', 'Every active account across every company.'],
                ] as $value => [$label, $hint])
                    <label class="flex cursor-pointer items-start gap-2.5 rounded-md border px-3 py-2.5
                                  {{ $scope === $value ? 'border-emergency bg-emergency-tint' : 'border-line hover:bg-surface-hover' }}">
                        <input type="radio" wire:model.live="scope" value="{{ $value }}"
                               class="mt-0.5 text-emergency focus:ring-emergency/40">
                        <span class="text-sm">
                            <span class="block font-semibold">{{ $label }}</span>
                            <span class="block text-xs text-content-muted">{{ $hint }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            <x-input-error :messages="$errors->get('scope')" />
        </fieldset>

        @if ($scope === 'company' || $scope === 'administration')
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="company" class="label">Company</label>
                    <select id="company" wire:model.live="companyId" class="field">
                        <option value="">Select a company</option>
                        @foreach ($this->companies as $company)
                            <option value="{{ $company->id }}">{{ $company->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('companyId')" />
                </div>

                @if ($scope === 'administration')
                    <div>
                        <label for="administration" class="label">Administration</label>
                        <select id="administration" wire:model.live="administrationId" class="field">
                            <option value="">Select an administration</option>
                            @foreach ($this->administrations as $administration)
                                <option value="{{ $administration->id }}">{{ $administration->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('administrationId')" />
                    </div>
                @endif
            </div>
        @endif

        <div>
            <label for="broadcast-body" class="label">Message</label>
            <textarea id="broadcast-body" wire:model="body" rows="4" class="field"
                      placeholder="Say what has happened and what people should do."></textarea>
            <p class="mt-1 text-xs text-content-subtle">
                Be specific and actionable. This will interrupt whatever every recipient is doing.
            </p>
            <x-input-error :messages="$errors->get('body')" />
        </div>

        <div class="flex flex-wrap items-center gap-3 border-t border-line pt-4">
            @if ($confirming)
                <div class="flex w-full items-start gap-3 rounded-md border-2 border-emergency bg-emergency-tint px-3 py-2.5">
                    <x-icon name="alert" class="mt-0.5 h-5 w-5 shrink-0 text-emergency-text" />
                    <p class="text-sm">
                        <span class="font-semibold text-emergency-text">
                            This will alert {{ number_format($this->audienceCount) }}
                            {{ Str::plural('person', $this->audienceCount) }} immediately.
                        </span>
                        <span class="text-content-muted">
                            It cannot be recalled, and every acknowledgement is permanently logged.
                        </span>
                    </p>
                </div>

                <button type="submit" class="btn-emergency" wire:loading.attr="disabled">
                    <x-icon name="megaphone" class="h-4 w-4" />
                    Send emergency broadcast
                </button>
                <button type="button" wire:click="$set('confirming', false)" class="btn-secondary">
                    Go back
                </button>
            @else
                <button type="submit" class="btn-emergency">
                    <x-icon name="alert" class="h-4 w-4" />
                    Review broadcast
                </button>
                <p class="text-xs text-content-muted">You will get a confirmation step before anything is sent.</p>
            @endif
        </div>
    </form>
</div>
