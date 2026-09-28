{{-- Mic, camera, screen, devices, size and leave. --}}

<div class="relative flex items-center gap-1.5 border-t border-brand-border px-3 py-2.5">
    <button type="button" @click="toggleMic()" class="btn btn-secondary !px-2.5"
            :aria-pressed="huddle.local.micOn ? 'false' : 'true'"
            :aria-label="huddle.local.micOn ? 'Mute your microphone' : 'Unmute your microphone'">
        <template x-if="huddle.local.micOn">
            <x-icon name="microphone" class="h-4 w-4" />
        </template>
        <template x-if="! huddle.local.micOn">
            <x-icon name="microphone-slash" class="h-4 w-4 text-alert" />
        </template>
    </button>

    <button type="button" @click="toggleCamera()" class="btn btn-secondary !px-2.5"
            :aria-pressed="huddle.local.camOn ? 'true' : 'false'"
            :aria-label="huddle.local.camOn ? 'Turn your camera off' : 'Turn your camera on'">
        <template x-if="huddle.local.camOn">
            <x-icon name="video" class="h-4 w-4" />
        </template>
        <template x-if="! huddle.local.camOn">
            <x-icon name="video-slash" class="h-4 w-4" />
        </template>
    </button>

    {{-- Screen share is one call in LiveKit and is the reason most huddles
         happen. Hidden where getDisplayMedia does not exist, rather than
         offered and then failing. --}}
    <button type="button" @click="toggleScreen()" x-show="canShareScreen" class="btn btn-secondary !px-2.5"
            :aria-pressed="huddle.local.screenOn ? 'true' : 'false'"
            :aria-label="huddle.local.screenOn ? 'Stop sharing your screen' : 'Share your screen'">
        <x-icon name="upload" class="h-4 w-4" />
    </button>

    {{-- Devices. People moving off a desk phone or a headset need this on the
         first day, and the browser default is rarely the one they want. The
         panel opens upward: these controls sit at the bottom of the dock. --}}
    <button type="button" @click="devicesOpen = ! devicesOpen" class="btn btn-secondary !px-2.5"
            :aria-expanded="devicesOpen ? 'true' : 'false'"
            aria-controls="huddle-devices"
            aria-label="Choose your microphone, camera and speaker">
        <x-icon name="cog" class="h-4 w-4" />
    </button>

    <div id="huddle-devices" x-show="devicesOpen" x-cloak
         @click.outside="devicesOpen = false"
         @keydown.escape.window="devicesOpen = false"
         class="absolute bottom-full left-3 z-10 mb-2 w-72 space-y-2.5 rounded-lg border border-line
                bg-surface-raised p-3 shadow-md">
        <template x-for="kind in deviceKinds" :key="kind">
            <div>
                <label :for="`huddle-device-${kind}`"
                       class="mb-1 block text-2xs font-medium text-content-subtle"
                       x-text="deviceLabel(kind)"></label>

                <select :id="`huddle-device-${kind}`"
                        class="field !py-1 text-xs"
                        :disabled="! huddle.devices[kind].length"
                        @change="selectDevice(kind, $event.target.value)">
                    {{-- No stored choice means the browser default, which has no
                         deviceId of its own to select. --}}
                    <option value="" x-show="! huddle.selected[kind]">System default</option>

                    <template x-for="device in huddle.devices[kind]" :key="device.deviceId">
                        <option :value="device.deviceId"
                                :selected="device.deviceId === huddle.selected[kind]"
                                x-text="device.label"></option>
                    </template>
                </select>

                <p x-show="! huddle.devices[kind].length" class="mt-1 text-2xs text-content-subtle">
                    Nothing detected.
                </p>
            </div>
        </template>
    </div>

    <button type="button" @click="cycleSurface()" class="btn btn-ghost ml-auto !px-2 !py-1.5"
            :aria-label="surfaceLabel">
        <x-icon name="chevron-down" class="h-4 w-4" />
    </button>

    <button type="button" @click="leave()" class="btn btn-emergency !py-1.5 !text-xs">
        <x-icon name="phone-hangup" class="h-4 w-4" />
        Leave
    </button>
</div>
