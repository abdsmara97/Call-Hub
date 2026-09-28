<div>
    <div class="mx-auto max-w-6xl px-4 py-6">
        @include('partials.admin-nav')

        <x-panel-heading title="Invitations"
                         description="Invite staff by email. Each invitation carries its org placement and role; the account exists only once it is accepted." />

        <div role="status" aria-live="polite" class="mb-4">
            @if (session('invited'))
                <div class="flex items-start gap-2 rounded-lg border border-brand-border bg-brand-tint px-3 py-2 text-sm text-brand-text">
                    <x-icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>Invitation sent to {{ session('invited') }}.</span>
                </div>
            @endif
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            {{-- ----------------------------------------------------------- form --}}
            <div class="panel p-4">
                <h2 class="text-sm font-semibold text-content">Send an invitation</h2>

                <form wire:submit="invite" class="mt-3 space-y-4">
                    <div>
                        <x-input-label for="email" value="Work email" />
                        <x-text-input wire:model="email" id="email" type="email" required />
                        <x-input-error :messages="$errors->get('email')" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="companyId" value="Company" />
                            <select wire:model.live="companyId" id="companyId" class="field w-full">
                                @foreach ($companies as $company)
                                    <option value="{{ $company->id }}">{{ $company->name }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('companyId')" />
                        </div>

                        <div>
                            <x-input-label for="administrationId" value="Administration" />
                            <select wire:model="administrationId" id="administrationId" class="field w-full">
                                @foreach ($administrations as $administration)
                                    <option value="{{ $administration->id }}">{{ $administration->name }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('administrationId')" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="role" value="Role" />
                        <select wire:model="role" id="role" class="field w-full">
                            @foreach ($roles as $roleOption)
                                <option value="{{ $roleOption }}">{{ Str::title($roleOption) }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('role')" />
                    </div>

                    <x-primary-button>Send invitation</x-primary-button>
                </form>
            </div>

            {{-- ----------------------------------------------------------- list --}}
            <div class="panel p-4">
                <h2 class="text-sm font-semibold text-content">Sent</h2>

                <ul class="mt-3 divide-y divide-line">
                    @forelse ($invitations as $invitation)
                        <li class="flex items-center justify-between gap-3 py-2.5">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">{{ $invitation->email }}</p>
                                <p class="truncate text-xs text-content-muted">
                                    {{ $invitation->administration?->name }} · {{ $invitation->company?->name }}
                                    · invited by {{ $invitation->inviter?->name }}
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                @if ($invitation->accepted_at)
                                    <span class="badge">Accepted</span>
                                @elseif ($invitation->expires_at->isPast())
                                    <span class="badge">Expired</span>
                                @else
                                    <span class="badge-brand">Pending</span>
                                    <button type="button" wire:click="revoke({{ $invitation->id }})"
                                            class="btn-ghost !px-2 !py-1 text-xs">
                                        Revoke
                                    </button>
                                @endif
                            </div>
                        </li>
                    @empty
                        <li class="py-4 text-sm text-content-muted">No invitations yet.</li>
                    @endforelse
                </ul>

                <div class="mt-3">{{ $invitations->links() }}</div>
            </div>
        </div>
    </div>
</div>
