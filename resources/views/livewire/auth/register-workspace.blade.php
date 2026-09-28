<div>
    <h2 class="mb-1 text-lg font-semibold tracking-tight">Create a workspace</h2>
    <p class="mb-5 text-sm text-content-muted">
        A workspace is your organisation's own space — its people, rooms, and
        emergency alerts are visible to nobody else.
    </p>

    <form wire:submit="register" class="space-y-4">
        <div>
            <x-input-label for="workspace" value="Workspace name" />
            <x-text-input wire:model="workspace" id="workspace" type="text" name="workspace"
                          required autofocus placeholder="Acme Logistics" />
            <x-input-error :messages="$errors->get('workspace')" />
        </div>

        <div>
            <x-input-label for="name" value="Your name" />
            <x-text-input wire:model="name" id="name" type="text" name="name"
                          required autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="email" value="Work email" />
            <x-text-input wire:model="email" id="email" type="email" name="email"
                          required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" />
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

        <x-primary-button class="w-full">Create workspace</x-primary-button>

        <p class="text-center text-sm text-content-muted">
            Already have an account?
            <a href="{{ route('login') }}" wire:navigate
               class="font-medium text-brand-text hover:underline">Sign in</a>
        </p>
    </form>
</div>
