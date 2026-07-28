<?php

namespace App\Livewire\Admin;

use App\Models\Emergency;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The permanent audit trail. Read-only by construction: nothing on this screen
 * can alter what was sent or who acknowledged it.
 */
#[Layout('layouts.app')]
class EmergencyLog extends Component
{
    use WithPagination;

    #[Url(as: 'from')]
    public string $from = '';

    #[Url(as: 'to')]
    public string $to = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $status = 'all';

    public ?int $expanded = null;

    public function mount(): void
    {
        Gate::authorize('viewLog', Emergency::class);
    }

    public function updatingFrom(): void
    {
        $this->resetPage();
    }

    public function updatingTo(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function toggle(int $emergencyId): void
    {
        $this->expanded = $this->expanded === $emergencyId ? null : $emergencyId;
    }

    public function clearFilters(): void
    {
        $this->reset('from', 'to', 'search', 'status');
        $this->resetPage();
    }

    public function render()
    {
        $emergencies = Emergency::query()
            ->with(['sender', 'room', 'company', 'administration'])
            // Aggregates rather than loading every recipient row for the list.
            ->withCount([
                'recipients',
                'recipients as acknowledged_count' => fn ($q) => $q->whereNotNull('acknowledged_at'),
            ])
            ->when($this->from !== '', fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to !== '', fn ($q) => $q->whereDate('created_at', '<=', $this->to))
            ->when($this->search !== '', function ($q) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';

                $q->where(function ($inner) use ($like) {
                    $inner->where('body', 'like', $like)
                        ->orWhereHas('sender', fn ($s) => $s->where('name', 'like', $like));
                });
            })
            ->when($this->status === 'open', fn ($q) => $q->whereNull('resolved_at'))
            ->when($this->status === 'resolved', fn ($q) => $q->whereNotNull('resolved_at'))
            ->when($this->status === 'unacknowledged', fn ($q) => $q->awaitingAcknowledgement())
            ->latest('id')
            ->paginate(20);

        $detail = $this->expanded
            ? Emergency::with(['recipients.user.administration', 'recipients.user.company'])->find($this->expanded)
            : null;

        return view('livewire.admin.emergency-log', [
            'emergencies' => $emergencies,
            'detail' => $detail,
        ]);
    }
}
