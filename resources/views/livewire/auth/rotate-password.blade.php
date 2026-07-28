<div>
    <h2 class="mb-1 text-lg font-semibold tracking-tight">Choose a new password</h2>
    <p class="mb-5 text-sm text-content-muted">
        Your account was set up with a temporary password. Replace it before continuing.
    </p>

    <form wire:submit="rotate" class="space-y-4">
        <div>
            <x-input-label for="current_password" value="Temporary password" />
            <x-text-input wire:model="current_password" id="current_password" type="password"
                          required autofocus autocomplete="current-password" />
            <x-input-error :messages="$errors->get('current_password')" />
        </div>

        <div>
            <x-input-label for="password" value="New password" />
            <x-text-input wire:model="password" id="password" type="password"
                          required autocomplete="new-password" />
            <p class="mt-1 text-xs text-content-subtle">At least 12 characters.</p>
            <x-input-error :messages="$errors->get('password')" />
        </div>

        <div>
            <x-input-label for="password_confirmation" value="Confirm new password" />
            <x-text-input wire:model="password_confirmation" id="password_confirmation" type="password"
                          required autocomplete="new-password" />
        </div>

        <div class="flex items-center justify-between gap-3">
            {{-- A form cannot nest, so sign-out posts from outside via form= --}}
            <button type="submit" form="rotate-logout" class="text-sm text-content-muted hover:underline">
                Sign out
            </button>

            <x-primary-button>Update password</x-primary-button>
        </div>
    </form>

    <form id="rotate-logout" method="POST" action="{{ route('logout') }}" class="hidden">
        @csrf
    </form>
</div>
