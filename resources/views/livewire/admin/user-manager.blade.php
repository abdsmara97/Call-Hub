<div>
    <div class="mx-auto max-w-6xl px-4 py-6">
        @include('partials.admin-nav')

        <x-panel-heading title="Employees"
                         description="Every account in the hub. New people get a one-time temporary password and are asked to change it at first sign-in." />

        {{-- Announced to screen readers whenever an action reports back. --}}
        <div role="status" aria-live="polite" class="mb-4">
            @if ($statusMessage)
                <div class="flex items-start gap-2 rounded-lg border border-brand-border bg-brand-tint px-3 py-2 text-sm text-brand-text">
                    <x-icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>{{ $statusMessage }}</span>
                </div>
            @endif
        </div>

        {{-- The temporary password is displayed once and never stored anywhere retrievable. --}}
        @if ($temporaryPassword)
            <div class="panel mb-4 border-emergency-border bg-emergency-tint p-4"
                 x-data="{ copied: false }">
                <div class="flex items-start gap-2">
                    <x-icon name="lock" class="mt-0.5 h-5 w-5 shrink-0 text-emergency-text" />
                    <div class="min-w-0 flex-1">
                        <h2 class="text-sm font-semibold text-emergency-text">
                            Temporary password for {{ $temporaryPasswordFor }}
                        </h2>
                        <p class="mt-1 text-xs text-content-muted">
                            Write this down or hand it over now. It will not be shown again — if it is lost you
                            will have to issue a new one with &ldquo;Reset password&rdquo;.
                        </p>

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <code x-ref="pw"
                                  class="select-all rounded-md border border-line bg-surface px-3 py-2 font-mono text-sm text-content">{{ $temporaryPassword }}</code>

                            <button type="button" class="btn-secondary !py-1.5 text-xs"
                                    x-on:click="navigator.clipboard.writeText($refs.pw.textContent.trim()); copied = true; setTimeout(() => copied = false, 2000)">
                                <x-icon name="document" class="h-4 w-4" />
                                <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                            </button>

                            <button type="button" class="btn-ghost !py-1.5 text-xs" wire:click="dismissPassword">
                                <x-icon name="x" class="h-4 w-4" />
                                Hide
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- ------------------------------------------------------------ filters --}}
        <div class="panel mb-4 p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div class="lg:col-span-2">
                    <label class="label" for="filter-search">Search</label>
                    <input id="filter-search" type="search" class="field"
                           placeholder="Name, email, phone or job title"
                           wire:model.live.debounce.300ms="search" />
                </div>

                <div>
                    <label class="label" for="filter-company">Company</label>
                    <select id="filter-company" class="field" wire:model.live="companyId">
                        <option value="">All companies</option>
                        @foreach ($companies as $company)
                            <option value="{{ $company->id }}">{{ $company->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="label" for="filter-administration">Administration</label>
                    <select id="filter-administration" class="field" wire:model.live="administrationId">
                        <option value="">All administrations</option>
                        @foreach ($filterAdministrations as $administration)
                            <option value="{{ $administration->id }}">{{ $administration->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" for="filter-role">Role</label>
                        <select id="filter-role" class="field" wire:model.live="role">
                            <option value="">Any</option>
                            @foreach ($roles as $roleOption)
                                <option value="{{ $roleOption }}">{{ ucfirst($roleOption) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="label" for="filter-status">Status</label>
                        <select id="filter-status" class="field" wire:model.live="status">
                            <option value="">Any</option>
                            @foreach ($statuses as $statusOption)
                                <option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                <button type="button" class="btn-ghost !py-1.5 text-xs" wire:click="clearFilters">
                    <x-icon name="x" class="h-4 w-4" />
                    Clear filters
                </button>

                <div class="flex items-center gap-2">
                    <a href="{{ route('admin.import') }}" class="btn-secondary !py-1.5 text-xs">
                        <x-icon name="upload" class="h-4 w-4" />
                        Import from CSV
                    </a>

                    <button type="button" class="btn-primary !py-1.5 text-xs" wire:click="create">
                        <x-icon name="plus" class="h-4 w-4" />
                        New employee
                    </button>
                </div>
            </div>
        </div>

        {{-- -------------------------------------------------------------- table --}}
        <div class="panel overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-left text-sm">
                    <caption class="sr-only">
                        Employee accounts, {{ $users->total() }} matching the current filters.
                    </caption>

                    <thead class="bg-surface-sunken text-2xs uppercase tracking-wide text-content-muted">
                        <tr>
                            <th scope="col" class="px-4 py-2 font-semibold">Employee</th>
                            <th scope="col" class="px-4 py-2 font-semibold">Contact</th>
                            <th scope="col" class="px-4 py-2 font-semibold">Organisation</th>
                            <th scope="col" class="px-4 py-2 font-semibold">Job title</th>
                            <th scope="col" class="px-4 py-2 font-semibold">Role</th>
                            <th scope="col" class="px-4 py-2 font-semibold">Status</th>
                            <th scope="col" class="px-4 py-2 text-right font-semibold">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($users as $user)
                            @php
                                $userRole = $user->roles->first()?->name ?? 'employee';
                                $isSelf = $user->id === auth()->id();
                                $isActive = $user->status === \App\Enums\UserStatus::Active;
                            @endphp

                            <tr wire:key="user-{{ $user->id }}" class="border-t border-line align-top">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <x-avatar :user="$user" size="sm" />
                                        <div class="min-w-0">
                                            <p class="truncate font-medium text-content">{{ $user->name }}</p>
                                            @if ($isSelf)
                                                <p class="text-2xs text-content-subtle">This is you</p>
                                            @endif
                                        </div>
                                    </div>
                                </td>

                                <td class="px-4 py-3">
                                    <p class="truncate text-content">{{ $user->email }}</p>
                                    <p class="truncate text-xs text-content-muted">{{ $user->phone ?: '—' }}</p>
                                </td>

                                <td class="px-4 py-3">
                                    <p class="truncate text-content">{{ $user->company?->name ?? '—' }}</p>
                                    <p class="truncate text-xs text-content-muted">{{ $user->administration?->name ?? '—' }}</p>
                                </td>

                                <td class="px-4 py-3 text-content-muted">{{ $user->job_title ?: '—' }}</td>

                                <td class="px-4 py-3">
                                    @if ($userRole === 'admin')
                                        <span class="badge-brand">
                                            <x-icon name="cog" class="h-3 w-3" />
                                            Admin
                                        </span>
                                    @else
                                        <span class="badge-neutral">
                                            <x-icon name="users" class="h-3 w-3" />
                                            Employee
                                        </span>
                                    @endif
                                </td>

                                <td class="px-4 py-3">
                                    @if ($isActive)
                                        <span class="badge-neutral">
                                            <x-icon name="check" class="h-3 w-3" />
                                            Active
                                        </span>
                                    @else
                                        <span class="badge-emergency">
                                            <x-icon name="lock" class="h-3 w-3" />
                                            Suspended
                                        </span>
                                    @endif
                                </td>

                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        <button type="button" class="btn-ghost !px-2 !py-1"
                                                wire:click="edit({{ $user->id }})"
                                                aria-label="Edit {{ $user->name }}"
                                                title="Edit {{ $user->name }}">
                                            <x-icon name="pencil" class="h-4 w-4" />
                                        </button>

                                        <button type="button" class="btn-ghost !px-2 !py-1"
                                                wire:click="resetPassword({{ $user->id }})"
                                                aria-label="Reset password for {{ $user->name }}"
                                                title="Reset password for {{ $user->name }}">
                                            <x-icon name="lock" class="h-4 w-4" />
                                        </button>

                                        @if ($isSelf)
                                            {{-- The policy refuses self-suspension; show why rather than 403 later. --}}
                                            <button type="button" class="btn-ghost !px-2 !py-1" disabled
                                                    aria-label="You cannot suspend your own account"
                                                    title="You cannot suspend your own account">
                                                <x-icon name="x" class="h-4 w-4" />
                                            </button>
                                        @elseif ($isActive)
                                            <button type="button" class="btn-ghost !px-2 !py-1"
                                                    wire:click="suspend({{ $user->id }})"
                                                    aria-label="Suspend {{ $user->name }}"
                                                    title="Suspend {{ $user->name }}">
                                                <x-icon name="x" class="h-4 w-4" />
                                            </button>
                                        @else
                                            <button type="button" class="btn-ghost !px-2 !py-1"
                                                    wire:click="reactivate({{ $user->id }})"
                                                    aria-label="Reactivate {{ $user->name }}"
                                                    title="Reactivate {{ $user->name }}">
                                                <x-icon name="check-circle" class="h-4 w-4" />
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr class="border-t border-line">
                                <td colspan="7" class="px-4 py-12 text-center">
                                    <x-icon name="users" class="mx-auto h-8 w-8 text-content-subtle" />
                                    <p class="mt-2 text-sm font-medium text-content">No employees match these filters</p>
                                    <p class="mt-1 text-xs text-content-muted">
                                        Try a different search, or clear the filters to see everyone.
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($users->hasPages())
            <nav class="mt-4 flex flex-wrap items-center justify-between gap-3" aria-label="Employee pagination">
                <p class="text-xs text-content-muted">
                    Showing {{ $users->firstItem() }}–{{ $users->lastItem() }} of {{ $users->total() }}
                </p>

                <div class="flex items-center gap-2">
                    <button type="button" class="btn-secondary !py-1.5 text-xs"
                            wire:click="previousPage" @disabled($users->onFirstPage())>
                        <x-icon name="chevron-left" class="h-4 w-4" />
                        Previous
                    </button>

                    <span class="text-xs text-content-muted">Page {{ $users->currentPage() }} of {{ $users->lastPage() }}</span>

                    <button type="button" class="btn-secondary !py-1.5 text-xs"
                            wire:click="nextPage" @disabled(! $users->hasMorePages())>
                        Next
                        <x-icon name="chevron-down" class="h-4 w-4 -rotate-90" />
                    </button>
                </div>
            </nav>
        @endif
    </div>

    {{-- --------------------------------------------------------------- modal --}}
    @if ($showModal)
        <div class="fixed inset-0 z-modal overflow-y-auto bg-canvas/80 p-4"
             role="dialog" aria-modal="true" aria-labelledby="user-form-title"
             x-data
             x-on:keydown.escape.window="$wire.closeModal()"
             x-init="$nextTick(() => $refs.firstField?.focus())">
            <div class="mx-auto mt-10 w-full max-w-xl panel bg-surface-raised p-5 shadow-lg">
                <div class="mb-4 flex items-start justify-between gap-3">
                    <div>
                        <h2 id="user-form-title" class="text-lg font-semibold tracking-tight">
                            {{ $editingId ? 'Edit employee' : 'New employee' }}
                        </h2>
                        <p class="mt-1 text-sm text-content-muted">
                            {{ $editingId
                                ? 'Changing the company or administration moves this person between department rooms.'
                                : 'A temporary password is generated on save and shown to you once.' }}
                        </p>
                    </div>

                    <button type="button" class="btn-ghost !px-2 !py-1" wire:click="closeModal"
                            aria-label="Close the employee form" title="Close">
                        <x-icon name="x" class="h-4 w-4" />
                    </button>
                </div>

                <form wire:submit="save" class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="form-name" value="Full name" />
                            <input id="form-name" type="text" class="field" x-ref="firstField"
                                   wire:model="name" autocomplete="off" />
                            <x-input-error :messages="$errors->get('name')" />
                        </div>

                        <div>
                            <x-input-label for="form-email" value="Email" />
                            <input id="form-email" type="email" class="field"
                                   wire:model="email" autocomplete="off" />
                            <x-input-error :messages="$errors->get('email')" />
                        </div>

                        <div>
                            <x-input-label for="form-phone" value="Phone (optional)" />
                            <input id="form-phone" type="text" class="field"
                                   wire:model="phone" autocomplete="off" />
                            <x-input-error :messages="$errors->get('phone')" />
                        </div>

                        <div>
                            <x-input-label for="form-job-title" value="Job title (optional)" />
                            <input id="form-job-title" type="text" class="field"
                                   wire:model="jobTitle" autocomplete="off" />
                            <x-input-error :messages="$errors->get('jobTitle')" />
                        </div>

                        <div>
                            <x-input-label for="form-company" value="Company" />
                            {{-- Live so the administration list can follow the choice. --}}
                            <select id="form-company" class="field" wire:model.live="formCompanyId">
                                <option value="">Choose a company</option>
                                @foreach ($companies as $company)
                                    <option value="{{ $company->id }}">{{ $company->name }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('formCompanyId')" />
                        </div>

                        <div>
                            <x-input-label for="form-administration" value="Administration" />
                            <select id="form-administration" class="field" wire:model="formAdministrationId"
                                    @disabled($formCompanyId === '')>
                                <option value="">
                                    {{ $formCompanyId === '' ? 'Choose a company first' : 'Choose an administration' }}
                                </option>
                                @foreach ($formAdministrations as $administration)
                                    <option value="{{ $administration->id }}">{{ $administration->name }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('formAdministrationId')" />
                        </div>

                        <div class="sm:col-span-2">
                            <x-input-label for="form-role" value="Role" />
                            <select id="form-role" class="field" wire:model="formRole">
                                @foreach ($roles as $roleOption)
                                    <option value="{{ $roleOption }}">{{ ucfirst($roleOption) }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-content-subtle">
                                Administrators can manage accounts, broadcast emergencies and change hub settings.
                            </p>
                            <x-input-error :messages="$errors->get('formRole')" />
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2 border-t border-line pt-4">
                        <x-secondary-button wire:click="closeModal">Cancel</x-secondary-button>
                        <x-primary-button wire:loading.attr="disabled">
                            {{ $editingId ? 'Save changes' : 'Create employee' }}
                        </x-primary-button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
