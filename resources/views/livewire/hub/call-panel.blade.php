<div>
    {{--
        wire:ignore is load-bearing here, not an optimisation. Livewire re-renders
        this component whenever anything on the page pushes an update, and a
        re-render that replaced this subtree would destroy the RTCPeerConnection
        and the microphone stream along with it — dropping a call because someone
        else sent a message.

        Sits at z-modal, deliberately below z-emergency: if an emergency lands
        during a call it must still be the thing on top.
    --}}
    <div wire:ignore x-data="callSession(@js($me), @js($callConfig))">
        @include('livewire.hub.partials.call-incoming')
        @include('livewire.hub.partials.call-active')
    </div>
</div>
