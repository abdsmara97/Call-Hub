<div>
    <div class="mx-auto max-w-6xl px-4 py-6">
        <x-panel-heading title="Directory"
                         description="Every active employee across the group. Search by name, email, job title or phone." />

        {{-- ------------------------------------------------------------ filters --}}
        <section class="panel mb-5 p-4" aria-labelledby="directory-filters-heading">
            <h2 id="directory-filters-heading" class="sr-only">Filter the directory</h2>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <x-input-label for="directory-search" value="Search" />
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                            <x-icon name="search" class="h-4 w-4 text-content-subtle" />
                        </span>
                        <x-text-input wire:model.live.debounce.300ms="search"
                                      id="directory-search"
                                      type="search"
                                      class="pl-9"
                                      placeholder="Name, email, title or phone" />
                    </div>
                </div>

                <div>
                    <x-input-label for="directory-company" value="Company" />
                    <select wire:model.live.debounce.300ms="companyId" id="directory-company" class="field">
                        <option value="">All companies</option>
                        @foreach ($companies as $company)
                            <option value="{{ $company->id }}">{{ $company->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <x-input-label for="directory-administration" value="Administration" />
                    <select wire:model.live.debounce.300ms="administrationId" id="directory-administration" class="field">
                        <option value="">All administrations</option>
                        @foreach ($administrations as $administration)
                            <option value="{{ $administration->id }}">{{ $administration->name }}</option>
                        @endforeach
                    </select>
                    @if ($this->companyId)
                        <p class="mt-1 text-2xs text-content-subtle">Limited to the selected company.</p>
                    @endif
                </div>

                <div>
                    <x-input-label for="directory-job-title" value="Job title" />
                    <select wire:model.live.debounce.300ms="jobTitle" id="directory-job-title" class="field">
                        <option value="">All job titles</option>
                        @foreach ($jobTitles as $title)
                            <option value="{{ $title }}">{{ $title }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                {{-- Result count is announced, so a filter change is perceivable without sight. --}}
                <p class="text-sm text-content-muted" role="status" aria-live="polite">
                    {{ $users->total() }} {{ Str::plural('employee', $users->total()) }} found
                </p>

                @if ($this->hasFilters())
                    <button type="button" wire:click="clearFilters" class="btn-ghost !py-1.5 text-xs">
                        <x-icon name="x" class="h-4 w-4" />
                        Clear filters
                    </button>
                @endif
            </div>
        </section>

        {{-- --------------------------------------------------------------- grid --}}
        @if ($users->isEmpty())
            <div class="panel flex flex-col items-center gap-3 px-6 py-14 text-center">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-surface-sunken">
                    <x-icon name="users" class="h-6 w-6 text-content-subtle" />
                </span>
                <h2 class="text-lg font-semibold">No one matches those filters</h2>
                <p class="max-w-md text-sm text-content-muted">
                    Try a shorter search term, or widen the company and administration filters.
                    Suspended accounts are never listed in the directory.
                </p>
                @if ($this->hasFilters())
                    <button type="button" wire:click="clearFilters" class="btn-secondary mt-1">
                        Clear all filters
                    </button>
                @endif
            </div>
        @else
            <ul role="list" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($users as $user)
                    @php $isSelf = auth()->id() === $user->id; @endphp

                    <li class="panel flex flex-col gap-3 p-4">
                        <div class="flex items-start gap-3">
                            <x-avatar :user="$user" size="lg" :presence="true" />

                            <div class="min-w-0 flex-1">
                                <p class="truncate text-base font-semibold text-content">
                                    {{ $user->name }}
                                    @if ($isSelf)
                                        <span class="badge-neutral ml-1 align-middle">You</span>
                                    @endif
                                </p>
                                <p class="truncate text-sm text-content-muted">
                                    {{ $user->job_title ?: 'No job title recorded' }}
                                </p>
                                <p class="truncate text-2xs text-content-subtle">
                                    {{ $user->administration?->name ?? 'No administration' }}
                                    ·
                                    {{ $user->company?->name ?? 'No company' }}
                                </p>
                            </div>
                        </div>

                        @if (filled($user->status_message))
                            <p class="rounded-md bg-surface-sunken px-3 py-2 text-sm text-content-muted">
                                <span class="sr-only">Status message:</span>
                                {{ $user->status_message }}
                            </p>
                        @endif

                        <dl class="grid grid-cols-[4.5rem_minmax(0,1fr)] gap-x-2 gap-y-1.5 text-sm">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-content-subtle">Email</dt>
                            <dd class="min-w-0">
                                <a href="mailto:{{ $user->email }}"
                                   class="block truncate text-brand-text hover:underline">{{ $user->email }}</a>
                            </dd>

                            <dt class="text-xs font-semibold uppercase tracking-wide text-content-subtle">Phone</dt>
                            <dd class="min-w-0">
                                @if (filled($user->phone))
                                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $user->phone) }}"
                                       class="block truncate text-brand-text hover:underline">{{ $user->phone }}</a>
                                @else
                                    <span class="text-content-subtle">Not recorded</span>
                                @endif
                            </dd>
                        </dl>

                        <div class="mt-auto pt-1">
                            @if ($isSelf)
                                <a href="{{ route('profile') }}" class="btn-secondary w-full !py-1.5 text-xs">
                                    <x-icon name="pencil" class="h-4 w-4" />
                                    Edit your profile
                                </a>
                            @else
                                <a href="{{ route('dm.start', $user) }}"
                                   class="btn-primary w-full !py-1.5 text-xs"
                                   aria-label="Send a direct message to {{ $user->name }}">
                                    <x-icon name="send" class="h-4 w-4" />
                                    Message
                                </a>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="mt-6">
                {{ $users->onEachSide(1)->links() }}
            </div>
        @endif
    </div>
</div>
