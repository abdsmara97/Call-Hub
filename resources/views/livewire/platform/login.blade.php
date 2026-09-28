<div>
    <h2 class="mb-1 text-lg font-semibold tracking-tight">Platform sign in</h2>
    <p class="mb-5 text-sm text-content-muted">
        Operators only. Workspace accounts sign in at
        <a href="{{ route('login') }}" wire:navigate class="font-medium text-brand-text hover:underline">the hub</a>.
    </p>

    <form wire:submit="login" class="space-y-4">
        <div>
            <x-input-label for="email" value="Email" />
            <x-text-input wire:model="email" id="email" type="email" name="email"
                          required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" />
        </div>

        <div>
            <x-input-label for="password" value="Password" />
            <x-text-input wire:model="password" id="password" type="password" name="password"
                          required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" />
        </div>

        <label for="remember" class="flex items-center gap-2 text-sm text-content-muted">
            <input wire:model="remember" id="remember" type="checkbox"
                   class="rounded border-line-strong text-brand focus:ring-brand/40">
            Keep me signed in
        </label>

        <x-primary-button class="w-full">Sign in</x-primary-button>
    </form>
</div>
