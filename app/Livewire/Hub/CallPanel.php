<?php

namespace App\Livewire\Hub;

use App\Support\HubSettings;
use Livewire\Component;

/**
 * Always mounted, on every page — the same reason EmergencyAlerts is.
 *
 * A call has to reach someone who is reading a different room, or the
 * directory, and the conversation view could never do that. This component
 * carries only the ring: once both parties are on the wire, everything else is
 * a client whisper and the server hears nothing more about it.
 *
 * Deliberately stateless. There is no call record to load, because there is no
 * calls table — a call that nobody answers leaves nothing behind but the
 * message the caller chose to send instead.
 */
class CallPanel extends Component
{
    protected function getListeners(): array
    {
        $me = auth()->id();

        return [
            "echo-private:App.Models.User.{$me},.call.ringing" => 'onRinging',
            "echo-private:App.Models.User.{$me},.call.cancelled" => 'onCancelled',
        ];
    }

    /**
     * The browser side owns the ringtone, the overlay and the peer connection;
     * the server just says "this happened".
     *
     * @param  array<string, mixed>  $payload
     */
    public function onRinging(array $payload = []): void
    {
        $this->dispatch('call-incoming', call: $payload);
    }

    /** @param  array<string, mixed>  $payload */
    public function onCancelled(array $payload = []): void
    {
        $this->dispatch('call-cancelled', call: $payload);
    }

    public function render(HubSettings $settings)
    {
        return view('livewire.hub.call-panel', [
            'me' => [
                'id' => auth()->id(),
                'name' => auth()->user()?->name,
            ],
            'callConfig' => [
                'ringSeconds' => $settings->callRingSeconds(),
                'calleeRingSeconds' => (int) config('hub.calls.callee_ring_seconds'),
                'alertGraceSeconds' => (int) config('hub.calls.alert_grace_seconds'),
                'connectTimeoutSeconds' => (int) config('hub.calls.connect_timeout_seconds'),
                'reconnectGraceSeconds' => (int) config('hub.calls.reconnect_grace_seconds'),
                'maxWhisperBytes' => (int) config('hub.calls.max_whisper_bytes'),
            ],
        ]);
    }
}
