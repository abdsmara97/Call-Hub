<div>
    <h2 class="mb-1 text-lg font-semibold tracking-tight">Join {{ $invitation?->tenant?->name }}</h2>
    <p class="mb-5 text-sm text-content-muted">
        You were invited as <span class="font-medium">{{ $invitation?->email }}</span>.
        Choose your name and a password to activate the account.
    </p>

    <form wire:submit="accept" class="space-y-4">
        <div>
            <x-input-label for="name" value="Your name" />
            <x-text-input wire:model="name" id="name" type="text" name="name"
                          required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="password" value="Password" />
            <x-text-input wire:model="password" id="password" type="password" name="password"
                          required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" />
        </div>

        <div>
            <x-input-label for="password_confirmation" value="Confirm password" />
            <x-text-input wire:model="password_confirmation" id="password_confirmation" type="password"
                          name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" />
        </div>

        <x-primary-button class="w-full">Join workspace</x-primary-button>
    </form>
</div>
