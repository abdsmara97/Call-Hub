<?php

namespace App\Livewire\Auth;

use App\Services\WorkspaceProvisioner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Self-serve workspace creation — the door a new customer walks in through.
 * Distinct from staff onboarding on purpose: this creates a tenant; staff
 * accounts are only ever created by invitation or import inside one.
 */
#[Layout('layouts.guest')]
class RegisterWorkspace extends Component
{
    public string $workspace = '';

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function register(WorkspaceProvisioner $workspaces): void
    {
        $input = $this->validate([
            'workspace' => ['required', 'string', 'min:2', 'max:80'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()->min(12)->uncompromised()],
        ]);

        $user = $workspaces->create($input);

        Auth::login($user);

        session()->regenerate();

        $this->redirectRoute('hub', navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.register-workspace')
            ->title('Create a workspace');
    }
}
