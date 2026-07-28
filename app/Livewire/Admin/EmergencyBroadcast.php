<?php

namespace App\Livewire\Admin;

use App\Enums\EmergencyScope;
use App\Exceptions\EmergencyRateLimited;
use App\Models\Administration;
use App\Models\Company;
use App\Models\Emergency;
use App\Models\User;
use App\Services\EmergencyService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Administrator broadcast: one action that reaches a company, an administration,
 * or the entire organisation.
 */
#[Layout('layouts.app')]
class EmergencyBroadcast extends Component
{
    public string $scope = 'company';

    public ?int $companyId = null;

    public ?int $administrationId = null;

    public string $body = '';

    /** Broadcasts are irreversible, so sending is a two-step action. */
    public bool $confirming = false;

    public ?int $sentEmergencyId = null;

    public function mount(): void
    {
        Gate::authorize('broadcast', Emergency::class);

        $this->companyId = auth()->user()->company_id;
    }

    public function updatedScope(): void
    {
        $this->confirming = false;

        if ($this->scope !== 'administration') {
            $this->administrationId = null;
        }
    }

    public function updatedCompanyId(): void
    {
        $this->administrationId = null;
        $this->confirming = false;
    }

    #[Computed]
    public function companies()
    {
        return Company::orderBy('name')->get();
    }

    #[Computed]
    public function administrations()
    {
        return Administration::query()
            ->when($this->companyId, fn ($q) => $q->where('company_id', $this->companyId))
            ->orderBy('name')
            ->get();
    }

    /** How many people this will actually alert, shown before confirming. */
    #[Computed]
    public function audienceCount(): int
    {
        $scope = EmergencyScope::tryFrom($this->scope);

        if (! $scope?->isBroadcast()) {
            return 0;
        }

        return User::query()
            ->active()
            ->where('id', '!=', auth()->id())
            ->when($scope === EmergencyScope::Company, fn ($q) => $q->where('company_id', $this->companyId))
            ->when($scope === EmergencyScope::Administration, fn ($q) => $q->where('administration_id', $this->administrationId))
            ->count();
    }

    public function review(): void
    {
        $this->validateForm();

        $this->confirming = true;
    }

    public function send(EmergencyService $emergencies): void
    {
        Gate::authorize('broadcast', Emergency::class);

        $this->validateForm();

        $scope = EmergencyScope::from($this->scope);

        try {
            $emergency = $emergencies->broadcastTo(
                auth()->user(),
                $scope,
                $this->body,
                $scope === EmergencyScope::Company ? Company::find($this->companyId) : null,
                $scope === EmergencyScope::Administration ? Administration::find($this->administrationId) : null,
            );
        } catch (EmergencyRateLimited $e) {
            $this->addError('body', $e->getMessage());
            $this->confirming = false;

            return;
        }

        $this->sentEmergencyId = $emergency->getKey();
        $this->reset('body', 'confirming');
    }

    private function validateForm(): void
    {
        $this->validate([
            'scope' => ['required', 'in:company,administration,all'],
            'companyId' => ['nullable', 'required_if:scope,company', 'exists:companies,id'],
            'administrationId' => ['nullable', 'required_if:scope,administration', 'exists:administrations,id'],
            'body' => ['required', 'string', 'min:10', 'max:2000'],
        ], attributes: [
            'companyId' => 'company',
            'administrationId' => 'administration',
            'body' => 'message',
        ]);
    }

    public function render()
    {
        return view('livewire.admin.emergency-broadcast');
    }
}
