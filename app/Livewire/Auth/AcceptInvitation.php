<?php

namespace App\Livewire\Auth;

use App\Models\Invitation;
use App\Services\UserProvisioner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The other end of an email invitation: choose a name and password, land in
 * the workspace. The token in the URL is the entire credential, so it is
 * checked on mount and again inside the accepting transaction.
 */
#[Layout('layouts.guest')]
class AcceptInvitation extends Component
{
    public string $token = '';

    public string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;

        if (! $this->invitation()?->isClaimable()) {
            abort(410, 'This invitation is no longer valid.');
        }
    }

    public function accept(UserProvisioner $users): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()->min(12)->uncompromised()],
        ]);

        $invitation = $this->invitation();

        if (! $invitation?->isClaimable()) {
            abort(410, 'This invitation is no longer valid.');
        }

        $user = DB::transaction(function () use ($users, $invitation) {
            [$user] = $users->create([
                'tenant_id' => $invitation->tenant_id,
                'name' => $this->name,
                'email' => $invitation->email,
                'company_id' => $invitation->company_id,
                'administration_id' => $invitation->administration_id,
                'role' => $invitation->role,
            ], $this->password);

            // They chose this password themselves — no rotation needed.
            $user->forceFill(['must_change_password' => false])->save();

            $invitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        });

        Auth::login($user);

        session()->regenerate();

        $this->redirectRoute('hub', navigate: true);
    }

    private function invitation(): ?Invitation
    {
        // Unscoped on purpose: the guest has no tenant context yet — the
        // token itself is what selects the tenant.
        return Invitation::acrossTenants()->where('token', $this->token)->first();
    }

    public function render()
    {
        return view('livewire.auth.accept-invitation', [
            'invitation' => $this->invitation(),
        ])->title('Accept invitation');
    }
}
