<?php

namespace App\Livewire\Admin;

use App\Mail\StaffInvitation;
use App\Models\Administration;
use App\Models\Company;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Invite staff by email — the self-serve counterpart to CSV import. An
 * invitation carries its org placement and role; accepting it is the only
 * thing that creates the account.
 */
#[Layout('layouts.app')]
class Invitations extends Component
{
    use WithPagination;

    public string $email = '';

    public string $role = Permissions::ROLE_EMPLOYEE;

    public ?int $companyId = null;

    public ?int $administrationId = null;

    public function mount(): void
    {
        Gate::authorize('manage', User::class);

        $this->companyId ??= Company::query()->orderBy('name')->value('id');
        $this->administrationId ??= Administration::query()
            ->where('company_id', $this->companyId)->orderBy('name')->value('id');
    }

    public function updatedCompanyId(): void
    {
        $this->administrationId = Administration::query()
            ->where('company_id', $this->companyId)->orderBy('name')->value('id');
    }

    public function invite(): void
    {
        Gate::authorize('manage', User::class);

        $this->validate([
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'in:'.implode(',', Permissions::roles())],
            'companyId' => ['required', 'exists:companies,id'],
            'administrationId' => ['required', 'exists:administrations,id'],
        ]);

        // Re-inviting replaces the previous token instead of stacking one.
        // The tenant is part of the match and set explicitly — inherited from
        // the inviter, not the request context, so the row lands in the right
        // tenant even where no context is bound.
        $invitation = Invitation::updateOrCreate(
            [
                'tenant_id' => auth()->user()->tenant_id,
                'email' => Str::lower(trim($this->email)),
            ],
            [
                'token' => Invitation::generateToken(),
                'role' => $this->role,
                'company_id' => $this->companyId,
                'administration_id' => $this->administrationId,
                'invited_by' => auth()->id(),
                'accepted_at' => null,
                'expires_at' => now()->addDays(7),
            ],
        );

        Mail::to($invitation->email)->send(new StaffInvitation($invitation));

        $this->reset('email');

        session()->flash('invited', $invitation->email);
    }

    public function revoke(int $invitationId): void
    {
        Gate::authorize('manage', User::class);

        Invitation::query()->whereKey($invitationId)->whereNull('accepted_at')->delete();
    }

    public function render()
    {
        return view('livewire.admin.invitations', [
            'invitations' => Invitation::query()
                ->with(['company', 'administration', 'inviter'])
                ->latest()
                ->paginate(25),
            'companies' => Company::query()->orderBy('name')->get(),
            'administrations' => Administration::query()
                ->where('company_id', $this->companyId)->orderBy('name')->get(),
            'roles' => Permissions::roles(),
        ])->title('Invitations');
    }
}
