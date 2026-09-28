<div>
    <div class="mx-auto max-w-6xl px-4 py-6">
        <x-panel-heading title="Workspaces"
                         description="Every customer on this installation. Creating a workspace issues its first administrator a temporary password — hand it over securely; it is shown exactly once." />

        @if ($temporaryPassword)
            <div class="mb-4 rounded-lg border border-brand-border bg-brand-tint p-4" role="status" aria-live="polite">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-brand-text">
                            {{ $createdWorkspace }} is ready.
                        </p>
                        <p class="mt-1 text-sm text-content-muted">
                            Temporary password for its administrator — shown once, then gone:
                        </p>
                        <code class="mt-2 inline-block select-all rounded-md border border-line bg-surface px-3 py-2 font-mono text-sm text-content">{{ $temporaryPassword }}</code>
                        <p class="mt-2 text-xs text-content-subtle">
                            They will be required to choose their own password at first sign-in.
                        </p>
                    </div>
                    <button type="button" wire:click="dismissPassword" class="btn-ghost !px-2" aria-label="Dismiss">
                        <x-icon name="x" class="h-4 w-4" />
                    </button>
                </div>
            </div>
        @endif

        <div class="grid gap-4 lg:grid-cols-2">
            {{-- ------------------------------------------------------- create --}}
            <div class="panel p-4">
                <h2 class="text-sm font-semibold text-content">New workspace</h2>

                <form wire:submit="create" class="mt-3 space-y-4">
                    <div>
                        <x-input-label for="workspace" value="Workspace name" />
                        <x-text-input wire:model="workspace" id="workspace" type="text"
                                      required placeholder="Acme Logistics" />
                        <x-input-error :messages="$errors->get('workspace')" />
                    </div>

                    <div>
                        <x-input-label for="adminName" value="Administrator name" />
                        <x-text-input wire:model="adminName" id="adminName" type="text" required />
                        <x-input-error :messages="$errors->get('adminName')" />
                    </div>

                    <div>
                        <x-input-label for="adminEmail" value="Administrator email" />
                        <x-text-input wire:model="adminEmail" id="adminEmail" type="email" required />
                        <x-input-error :messages="$errors->get('adminEmail')" />
                    </div>

                    <x-primary-button>Create workspace</x-primary-button>
                </form>
            </div>

            {{-- --------------------------------------------------------- list --}}
            <div class="panel p-4">
                <h2 class="text-sm font-semibold text-content">All workspaces</h2>

                <ul class="mt-3 divide-y divide-line">
                    @forelse ($tenants as $tenant)
                        <li class="flex items-center justify-between gap-3 py-2.5">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">{{ $tenant->name }}</p>
                                <p class="truncate text-xs text-content-muted">
                                    {{ $tenant->slug }} · created {{ $tenant->created_at->toFormattedDateString() }}
                                </p>
                            </div>
                            <span class="badge shrink-0 tabular-nums">
                                {{ $tenant->users_count }} {{ Str::plural('account', $tenant->users_count) }}
                            </span>
                        </li>
                    @empty
                        <li class="py-4 text-sm text-content-muted">No workspaces yet.</li>
                    @endforelse
                </ul>

                <div class="mt-3">{{ $tenants->links() }}</div>
            </div>
        </div>
    </div>
</div>
