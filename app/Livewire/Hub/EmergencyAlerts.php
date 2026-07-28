<?php

namespace App\Livewire\Hub;

use App\Models\Emergency;
use App\Models\EmergencyRecipient;
use App\Services\EmergencyService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Always mounted, on every page. This is what makes an emergency reach someone
 * who is in a different room, on the directory, or with the tab in the
 * background — the room view alone could never do that.
 */
class EmergencyAlerts extends Component
{
    protected function getListeners(): array
    {
        $me = auth()->id();

        return [
            "echo-private:App.Models.User.{$me},.emergency.sent" => 'onEmergency',
            "echo-private:App.Models.User.{$me},.emergency.escalated" => 'onEscalation',
            "echo-private:App.Models.User.{$me},.emergency.resolved" => '$refresh',
            'emergency-acknowledged' => 'refreshPending',
        ];
    }

    /** @param  array<string, mixed>  $payload */
    public function onEmergency(array $payload = []): void
    {
        unset($this->pending);

        // The browser side owns sound, the title flash and the screen-reader
        // announcement; the server just says "this happened".
        $this->dispatch('emergency-alert',
            body: $payload['body'] ?? '',
            sender: $payload['sender_name'] ?? 'A colleague',
            isBroadcast: (bool) ($payload['is_broadcast'] ?? false),
        );
    }

    /** @param  array<string, mixed>  $payload */
    public function onEscalation(array $payload = []): void
    {
        unset($this->pending);

        $this->dispatch('emergency-alert',
            body: $payload['body'] ?? '',
            sender: $payload['sender_name'] ?? 'A colleague',
            isBroadcast: false,
            isEscalation: true,
        );
    }

    #[On('emergency-acknowledged')]
    public function refreshPending(): void
    {
        unset($this->pending);
    }

    /**
     * Emergencies aimed at this user that are still open and still unacknowledged.
     *
     * @return Collection<int, Emergency>
     */
    #[Computed]
    public function pending(): Collection
    {
        return Emergency::query()
            ->unresolved()
            ->whereHas(
                'recipients',
                fn ($q) => $q->where('user_id', auth()->id())->whereNull('acknowledged_at')
            )
            ->with('sender')
            ->latest('id')
            ->get();
    }

    public function acknowledge(int $emergencyId, EmergencyService $emergencies): void
    {
        $emergency = Emergency::findOrFail($emergencyId);

        Gate::authorize('acknowledge', $emergency);

        $emergencies->acknowledge($emergency, auth()->user());

        unset($this->pending);

        $this->dispatch('emergency-acknowledged', emergencyId: $emergencyId);
        $this->dispatch('emergency-cleared');
    }

    /** Acknowledges everything at once when several have stacked up. */
    public function acknowledgeAll(EmergencyService $emergencies): void
    {
        foreach ($this->pending as $emergency) {
            if (Gate::allows('acknowledge', $emergency)) {
                $emergencies->acknowledge($emergency, auth()->user());
            }
        }

        unset($this->pending);

        $this->dispatch('emergency-acknowledged');
        $this->dispatch('emergency-cleared');
    }

    public function render()
    {
        return view('livewire.hub.emergency-alerts');
    }
}
