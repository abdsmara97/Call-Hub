<?php

namespace App\Livewire\Platform;

use App\Models\Tenant;
use App\Services\WorkspaceProvisioner;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The platform operator's panel: every workspace on the installation, and
 * the only place a new one can be created. Lives behind its own guard and
 * layout — an operator is signed into the panel, not into any workspace.
 */
#[Layout('layouts.platform')]
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
        abort_unless(auth('platform')->check(), 403);
    }

    public function create(WorkspaceProvisioner $workspaces): void
    {
        // Re-checked here, not just in mount: Livewire actions arrive on
        // their own requests and must not outlive the operator's session.
        abort_unless(auth('platform')->check(), 403);

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
                // An operator has no tenant, so nothing is bound — but the
                // count still bypasses the scope explicitly, so it stays
                // correct even if this ever renders inside a bound context.
                ->withCount(['users' => fn ($q) => $q->withoutGlobalScope('tenant')])
                ->orderBy('name')
                ->paginate(25),
        ])->title('Workspaces');
    }
}
