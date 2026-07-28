<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The only screen reachable while an account still holds the temporary password
 * an administrator issued.
 */
#[Layout('layouts.guest')]
class RotatePassword extends Component
{
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        // Nothing to rotate — send the user where they were going.
        if (! Auth::user()->must_change_password) {
            $this->redirectRoute('hub', navigate: true);
        }
    }

    public function rotate(): void
    {
        $this->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()->min(12)->uncompromised()],
        ], attributes: [
            'current_password' => 'temporary password',
        ]);

        $user = Auth::user();

        $user->forceFill([
            'password' => $this->password,
            'must_change_password' => false,
        ])->save();

        // A password change is a good moment to cut any other live session.
        Auth::logoutOtherDevices($this->password);
        session()->regenerate();

        session()->flash('status', 'Your password has been updated.');

        $this->redirectRoute('hub', navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.rotate-password');
    }
}
