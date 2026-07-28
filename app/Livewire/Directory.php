<?php

namespace App\Livewire;

use App\Models\Administration;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The employee directory. Open to every signed-in employee by design — see
 * UserPolicy::viewAny. Only active accounts are listed; suspended accounts keep
 * their history but disappear from the org's address book.
 */
#[Layout('layouts.app')]
class Directory extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'company', except: '')]
    public string $companyId = '';

    #[Url(as: 'administration', except: '')]
    public string $administrationId = '';

    #[Url(as: 'title', except: '')]
    public string $jobTitle = '';

    private const PER_PAGE = 24;

    // ------------------------------------------------------------------ hooks

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

    public function updatingJobTitle(): void
    {
        $this->resetPage();
    }

    /** Changing company invalidates whichever administration was selected. */
    public function updatedCompanyId(): void
    {
        $this->administrationId = '';
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'companyId', 'administrationId', 'jobTitle']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return filled($this->search)
            || filled($this->companyId)
            || filled($this->administrationId)
            || filled($this->jobTitle);
    }

    // ------------------------------------------------------------------ query

    public function render()
    {
        $users = User::query()
            ->active()
            ->search($this->search)
            ->when($this->companyId, fn (Builder $q) => $q->where('company_id', (int) $this->companyId))
            ->when($this->administrationId, fn (Builder $q) => $q->where('administration_id', (int) $this->administrationId))
            ->when($this->jobTitle, fn (Builder $q) => $q->where('job_title', $this->jobTitle))
            // Eager loaded so a 24-card grid stays at three queries, not fifty.
            ->with(['company', 'administration'])
            ->orderBy('name')
            ->paginate(self::PER_PAGE);

        return view('livewire.directory', [
            'users' => $users,
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
            'administrations' => $this->administrationOptions(),
            'jobTitles' => $this->jobTitleOptions(),
        ]);
    }

    /** Administrations are narrowed to the selected company, if any. */
    private function administrationOptions()
    {
        return Administration::query()
            ->when($this->companyId, fn (Builder $q) => $q->where('company_id', (int) $this->companyId))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** @return \Illuminate\Support\Collection<int, string> */
    private function jobTitleOptions()
    {
        return User::query()
            ->active()
            ->whereNotNull('job_title')
            ->where('job_title', '!=', '')
            ->select('job_title')
            ->distinct()
            ->orderBy('job_title')
            ->pluck('job_title');
    }
}
