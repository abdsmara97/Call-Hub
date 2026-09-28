<?php

namespace App\Livewire\Platform;

use App\Models\Tenant;
use App\Services\WorkspaceProvisioner;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The platform operator's panel: every workspace on the installation, and
 * the only place a new one can be created. Deliberately not part of the
 * tenant admin area — a tenant admin runs their workspace; this screen
 * runs the platform.
 */
#[Layout('layouts.app')]
class Tenants extends Component
{
    use WithPagination;

    public string $workspace = '';

    public string $adminName = '';

    public string $adminEmail = '';

    /** Shown exactly once, after creation — the same pattern UserManager uses. */
    public ?string $temporaryPassword = null;

    public ?string $createdWorkspace = null;

    public function mount(): void
    {
        Gate::authorize('manage-platform');
    }

    public function create(WorkspaceProvisioner $workspaces): void
    {
        Gate::authorize('manage-platform');

        $input = $this->validate([
            'workspace' => ['required', 'string', 'min:2', 'max:80'],
            'adminName' => ['required', 'string', 'max:255'],
            'adminEmail' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
        ]);

        [$tenant, $admin, $temporaryPassword] = $workspaces->create([
            'workspace' => $input['workspace'],
            'name' => $input['adminName'],
            'email' => $input['adminEmail'],
        ]);

        $this->reset('workspace', 'adminName', 'adminEmail');

        // Held in component state, never in session or logs: gone on refresh.
        $this->temporaryPassword = $temporaryPassword;
        $this->createdWorkspace = $tenant->name;
    }

    public function dismissPassword(): void
    {
        $this->reset('temporaryPassword', 'createdWorkspace');
    }

    public function render()
    {
        return view('livewire.platform.tenants', [
            'tenants' => Tenant::query()
                // The operator's own request is tenant-bound like any other;
                // the count has to look across the fence on purpose.
                ->withCount(['users' => fn ($q) => $q->withoutGlobalScope('tenant')])
                ->orderBy('name')
                ->paginate(25),
        ])->title('Workspaces');
    }
}
