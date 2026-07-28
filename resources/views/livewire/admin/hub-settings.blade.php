<div>
    <div class="mx-auto max-w-6xl px-4 py-6">
        @include('partials.admin-nav')

        <x-panel-heading title="Hub settings"
                         description="How hard the emergency flag pushes, and how often anyone is allowed to raise it. Changes apply to new emergencies only — anything already in flight keeps the numbers it was sent with." />

        {{-- Confirmation lives in a live region so it is not a purely visual event. --}}
        <div role="status" aria-live="polite" class="mb-4">
            @if ($statusMessage)
                <div class="flex items-start gap-2 rounded-lg border border-brand-border bg-brand-tint px-3 py-2 text-sm text-brand-text">
                    <x-icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>{{ $statusMessage }}</span>
                </div>
            @endif
        </div>

        <form wire:submit="save" class="space-y-4">
            <div class="panel p-5">
                <h2 class="text-sm font-semibold text-content">Escalation</h2>
                <p class="mt-1 text-xs text-content-muted">
                    What happens while an emergency sits unacknowledged.
                </p>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="escalation-interval" value="Escalation interval (minutes)" />
                        <input id="escalation-interval" type="number" inputmode="numeric" min="1" max="60"
                               class="field" wire:model="escalationIntervalMinutes"
                               aria-describedby="escalation-interval-help" />
                        <p id="escalation-interval-help" class="mt-1 text-xs text-content-subtle">
                            How long the hub waits for an acknowledgement before alerting the same person again.
                        </p>
                        <x-input-error :messages="$errors->get('escalationIntervalMinutes')" />
                    </div>

                    <div>
                        <x-input-label for="max-escalations" value="Maximum escalations" />
                        <input id="max-escalations" type="number" inputmode="numeric" min="0" max="20"
                               class="field" wire:model="maxEscalations"
                               aria-describedby="max-escalations-help" />
                        <p id="max-escalations-help" class="mt-1 text-xs text-content-subtle">
                            How many times someone is re-alerted before the hub gives up and reports them as unreachable.
                        </p>
                        <x-input-error :messages="$errors->get('maxEscalations')" />
                    </div>
                </div>
            </div>

            <div class="panel p-5">
                <h2 class="text-sm font-semibold text-content">Rate limits</h2>
                <p class="mt-1 text-xs text-content-muted">
                    Applied to ordinary employees. Scarcity is what keeps the flag believable.
                </p>

                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <div>
                        <x-input-label for="rate-per-window" value="Emergencies per window" />
                        <input id="rate-per-window" type="number" inputmode="numeric" min="1" max="10"
                               class="field" wire:model="rateLimitPerWindow"
                               aria-describedby="rate-per-window-help" />
                        <p id="rate-per-window-help" class="mt-1 text-xs text-content-subtle">
                            How many emergencies one person may raise inside a single window.
                        </p>
                        <x-input-error :messages="$errors->get('rateLimitPerWindow')" />
                    </div>

                    <div>
                        <x-input-label for="rate-window" value="Window length (minutes)" />
                        <input id="rate-window" type="number" inputmode="numeric" min="1" max="120"
                               class="field" wire:model="rateLimitWindowMinutes"
                               aria-describedby="rate-window-help" />
                        <p id="rate-window-help" class="mt-1 text-xs text-content-subtle">
                            How long that window lasts before the allowance resets.
                        </p>
                        <x-input-error :messages="$errors->get('rateLimitWindowMinutes')" />
                    </div>

                    <div>
                        <x-input-label for="rate-per-day" value="Emergencies per day" />
                        <input id="rate-per-day" type="number" inputmode="numeric" min="1" max="100"
                               class="field" wire:model="rateLimitPerDay"
                               aria-describedby="rate-per-day-help" />
                        <p id="rate-per-day-help" class="mt-1 text-xs text-content-subtle">
                            The hard ceiling on how many emergencies one person may raise in a calendar day.
                        </p>
                        <x-input-error :messages="$errors->get('rateLimitPerDay')" />
                    </div>
                </div>
            </div>

            <div class="panel p-5">
                <h2 class="text-sm font-semibold text-content">Oversight</h2>
                <p class="mt-1 text-xs text-content-muted">
                    Where the line sits between heavy use and misuse.
                </p>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="misuse-threshold" value="Misuse threshold (per week)" />
                        <input id="misuse-threshold" type="number" inputmode="numeric" min="1" max="50"
                               class="field" wire:model="misuseThresholdPerWeek"
                               aria-describedby="misuse-threshold-help" />
                        <p id="misuse-threshold-help" class="mt-1 text-xs text-content-subtle">
                            Sending more than this many emergencies in a rolling week puts a person on the
                            <a href="{{ route('admin.misuse') }}" class="underline">misuse report</a>.
                        </p>
                        <x-input-error :messages="$errors->get('misuseThresholdPerWeek')" />
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2">
                <x-primary-button wire:loading.attr="disabled">
                    <x-icon name="check" class="h-4 w-4" />
                    Save settings
                </x-primary-button>
            </div>
        </form>
    </div>
</div>
