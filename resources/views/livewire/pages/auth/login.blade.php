<?php

use App\Livewire\Forms\LoginForm;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public LoginForm $form;

    public function login(): void
    {
        $this->validate();

        $this->form->authenticate();

        Session::regenerate();

        // The rotation middleware redirects anyone still on a temporary password.
        $this->redirectIntended(default: route('hub', absolute: false), navigate: true);
    }
}; ?>

<div>
    <h2 class="mb-1 text-lg font-semibold tracking-tight">Sign in</h2>
    <p class="mb-5 text-sm text-content-muted">Use your work email address.</p>

    <x-auth-session-status :status="session('status')" />

    <form wire:submit="login" class="space-y-4">
        <div>
            <x-input-label for="email" value="Work email" />
            <x-text-input wire:model="form.email" id="email" type="email" name="email"
                          required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('form.email')" />
        </div>

        <div>
            <x-input-label for="password" value="Password" />
            <x-text-input wire:model="form.password" id="password" type="password" name="password"
                          required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('form.password')" />
        </div>

        <div class="flex items-center justify-between">
            <label for="remember" class="flex items-center gap-2 text-sm text-content-muted">
                <input wire:model="form.remember" id="remember" type="checkbox"
                       class="rounded border-line-strong text-brand focus:ring-brand/40">
                Keep me signed in
            </label>

            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" wire:navigate
                   class="text-sm font-medium text-brand-text hover:underline">Forgot password?</a>
            @endif
        </div>

        <x-primary-button class="w-full">Sign in</x-primary-button>

        <p class="text-center text-sm text-content-muted">
            New organisation?
            <a href="{{ route('signup') }}" wire:navigate
               class="font-medium text-brand-text hover:underline">Create a workspace</a>
        </p>
    </form>
</div>
