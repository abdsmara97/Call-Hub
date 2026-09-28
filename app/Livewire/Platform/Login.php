<?php

namespace App\Livewire\Platform;

use App\Models\PlatformAdmin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The operator's own front door. Authenticates against the `platform`
 * guard, so this session and a hub session never mix — an operator is
 * signed into the panel, not into any workspace.
 */
#[Layout('layouts.guest')]
class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Same throttle shape as the hub login: five tries, then a cooldown.
        $key = 'platform-login:'.strtolower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => __('auth.throttle', [
                    'seconds' => RateLimiter::availableIn($key),
                    'minutes' => ceil(RateLimiter::availableIn($key) / 60),
                ]),
            ]);
        }

        if (! Auth::guard('platform')->attempt(
            ['email' => $this->email, 'password' => $this->password],
            $this->remember,
        )) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($key);

        session()->regenerate();

        $this->redirectRoute('platform.tenants');
    }

    public function render()
    {
        return view('livewire.platform.login')->title('Platform sign in');
    }
}
