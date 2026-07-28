<?php

namespace App\Livewire\Admin;

use App\Enums\UserStatus;
use App\Models\Administration;
use App\Models\Company;
use App\Models\User;
use App\Services\UserProvisioner;
use App\Support\Permissions;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The employee register. Every account in the hub is born, edited, suspended and
 * reset from here, and always through UserProvisioner so the admin form and the
 * CSV import cannot drift apart.
 */
#[Layout('layouts.app')]
class UserManager extends Component
{
    use WithPagination;

    private const PER_PAGE = 25;

    // ---------------------------------------------------------------- filters

    #[Url(as: 'search', except: '')]
    public string $search = '';

    #[Url(as: 'company', except: '')]
    public string $companyId = '';

    #[Url(as: 'administration', except: '')]
    public string $administrationId = '';

    #[Url(as: 'role', except: '')]
    public string $role = '';

    #[Url(as: 'status', except: '')]
    public string $status = '';

    // ------------------------------------------------------------- form state

    public bool $showModal = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $formCompanyId = '';

    public string $formAdministrationId = '';

    public string $jobTitle = '';

    public string $formRole = Permissions::ROLE_EMPLOYEE;

    // ------------------------------------------------------------ transients

    /** Shown exactly once, then dropped. Never persisted anywhere. */
    public string $temporaryPassword = '';

    public string $temporaryPasswordFor = '';

    public string $statusMessage = '';

    public function mount(): void
    {
        $this->authorize('manage', User::class);
    }

    // ---------------------------------------------------------------- filters

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingCompanyId(): void
    {
        $this->resetPage();
    }

    public function updatingAdministrationId(): void
    {
        $this->resetPage();
    }

    public function updatingRole(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    /** A different company means a different set of administrations. */
    public function updatedCompanyId(): void
    {
        $this->administrationId = '';
    }

    public function updatedFormCompanyId(): void
    {
        $this->formAdministrationId = '';
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'companyId', 'administrationId', 'role', 'status']);
        $this->resetPage();
    }

    // ------------------------------------------------------------------ modal

    public function create(): void
    {
        $this->authorize('create', User::class);

        $this->resetValidation();
        $this->reset(['editingId', 'name', 'email', 'phone', 'formCompanyId', 'formAdministrationId', 'jobTitle', 'formRole']);
        $this->dismissPassword();

        $this->showModal = true;
    }

    public function edit(int $userId): void
    {
        $user = User::findOrFail($userId);

        $this->authorize('update', $user);

        $this->resetValidation();
        $this->dismissPassword();

        $this->editingId = $user->id;
        $this->name = (string) $user->name;
        $this->email = (string) $user->email;
        $this->phone = (string) $user->phone;
        $this->formCompanyId = (string) $user->company_id;
        $this->formAdministrationId = (string) $user->administration_id;
        $this->jobTitle = (string) $user->job_title;
        $this->formRole = $user->roles->first()?->name ?? Permissions::ROLE_EMPLOYEE;

        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetValidation();
    }

    public function dismissPassword(): void
    {
        $this->temporaryPassword = '';
        $this->temporaryPasswordFor = '';
    }

    // ------------------------------------------------------------------- save

    public function save(UserProvisioner $provisioner): void
    {
        $editing = $this->editingId ? User::findOrFail($this->editingId) : null;

        $editing
            ? $this->authorize('update', $editing)
            : $this->authorize('create', User::class);

        $data = $this->validate($this->rules(), attributes: [
            'formCompanyId' => 'company',
            'formAdministrationId' => 'administration',
            'jobTitle' => 'job title',
            'formRole' => 'role',
        ]);

        $attributes = [
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?: null,
            'company_id' => (int) $data['formCompanyId'],
            'administration_id' => (int) $data['formAdministrationId'],
            'job_title' => $data['jobTitle'] ?: null,
            'role' => $data['formRole'],
        ];

        if ($editing) {
            $provisioner->update($editing, $attributes);

            $this->statusMessage = "{$attributes['name']}'s account was updated.";
        } else {
            [$user, $password] = $provisioner->create($attributes);

            $this->temporaryPassword = $password;
            $this->temporaryPasswordFor = $user->name;
            $this->statusMessage = "{$user->name} was added. Their temporary password is shown below.";
        }

        $this->showModal = false;
        $this->resetPage();
    }

    /** @return array<string, array<int, mixed>> */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->editingId),
            ],
            'phone' => ['nullable', 'string', 'max:40'],
            'formCompanyId' => ['required', 'integer', 'exists:companies,id'],
            'formAdministrationId' => [
                'required', 'integer', 'exists:administrations,id',
                // An administration always belongs to exactly one company, and
                // mismatching the two would put someone in the wrong rooms.
                function (string $attribute, mixed $value, callable $fail) {
                    $belongs = Administration::query()
                        ->whereKey($value)
                        ->where('company_id', (int) $this->formCompanyId)
                        ->exists();

                    if (! $belongs) {
                        $fail('The chosen administration does not belong to the chosen company.');
                    }
                },
            ],
            'jobTitle' => ['nullable', 'string', 'max:120'],
            'formRole' => ['required', Rule::in(Permissions::roles())],
        ];
    }

    // ---------------------------------------------------------------- actions

    public function suspend(int $userId, UserProvisioner $provisioner): void
    {
        $user = User::findOrFail($userId);

        $this->authorize('suspend', $user);

        $provisioner->suspend($user);

        $this->statusMessage = "{$user->name} was suspended and can no longer sign in.";
    }

    public function reactivate(int $userId, UserProvisioner $provisioner): void
    {
        $user = User::findOrFail($userId);

        $this->authorize('suspend', $user);

        $provisioner->reactivate($user);

        $this->statusMessage = "{$user->name} was reactivated.";
    }

    public function resetPassword(int $userId, UserProvisioner $provisioner): void
    {
        $user = User::findOrFail($userId);

        $this->authorize('update', $user);

        $this->temporaryPassword = $provisioner->resetPassword($user);
        $this->temporaryPasswordFor = $user->name;
        $this->statusMessage = "A new temporary password was issued for {$user->name}.";
    }

    // ----------------------------------------------------------------- render

    public function render()
    {
        $companies = Company::query()->orderBy('name')->get(['id', 'name']);

        $users = User::query()
            ->with(['company:id,name', 'administration:id,name', 'roles:id,name'])
            ->search($this->search)
            ->when($this->companyId !== '', fn ($q) => $q->where('company_id', (int) $this->companyId))
            ->when($this->administrationId !== '', fn ($q) => $q->where('administration_id', (int) $this->administrationId))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->role !== '', fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', $this->role)))
            ->orderBy('name')
            ->paginate(self::PER_PAGE);

        return view('livewire.admin.user-manager', [
            'users' => $users,
            'companies' => $companies,
            'filterAdministrations' => $this->administrationsFor($this->companyId),
            'formAdministrations' => $this->administrationsFor($this->formCompanyId),
            'statuses' => UserStatus::cases(),
            'roles' => Permissions::roles(),
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, Administration> */
    private function administrationsFor(string $companyId)
    {
        return Administration::query()
            ->when($companyId !== '', fn ($q) => $q->where('company_id', (int) $companyId))
            ->orderBy('name')
            ->get(['id', 'name', 'company_id']);
    }
}
