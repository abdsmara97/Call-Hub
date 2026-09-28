<?php

namespace App\Livewire\Admin;

use App\Models\Setting;
use App\Models\User;
use App\Support\HubSettings as HubSettingsReader;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Runtime tuning for the emergency flag and for 1:1 calling.
 *
 * These values are written to the settings table rather than config/hub.php so
 * an administrator can tighten them without a deploy. Historical emergencies
 * keep the numbers they were sent with — changing them here is never retroactive.
 */
#[Layout('layouts.app')]
class HubSettings extends Component
{
    /** @var int|string */
    public $escalationIntervalMinutes = 2;

    /** @var int|string */
    public $maxEscalations = 5;

    /** @var int|string */
    public $rateLimitPerWindow = 1;

    /** @var int|string */
    public $rateLimitWindowMinutes = 5;

    /** @var int|string */
    public $rateLimitPerDay = 8;

    /** @var int|string */
    public $misuseThresholdPerWeek = 5;

    /** The kill switch: turns 1:1 calling off across the hub without a deploy. */
    public bool $callsEnabled = true;

    /** @var int|string */
    public $callRingSeconds = 35;

    /**
     * The huddle kill switch, separate from callsEnabled on purpose.
     *
     * Huddle media crosses this server and 1:1 call media does not, so during a
     * bandwidth incident an administrator needs to stop the expensive one
     * without also taking away the free one.
     */
    public bool $huddlesEnabled = true;

    /** @var int|string */
    public $huddleMaxParticipants = 30;

    public string $statusMessage = '';

    public function mount(HubSettingsReader $settings): void
    {
        $this->authorize('manage', User::class);

        $this->escalationIntervalMinutes = $settings->escalationIntervalMinutes();
        $this->maxEscalations = $settings->maxEscalations();
        $this->rateLimitPerWindow = $settings->rateLimitPerWindow();
        $this->rateLimitWindowMinutes = $settings->rateLimitWindowMinutes();
        $this->rateLimitPerDay = $settings->rateLimitPerDay();
        $this->misuseThresholdPerWeek = $settings->misuseThresholdPerWeek();
        $this->callsEnabled = $settings->callsEnabled();
        $this->callRingSeconds = $settings->callRingSeconds();
        $this->huddlesEnabled = $settings->huddlesEnabled();
        $this->huddleMaxParticipants = $settings->huddleMaxParticipants();
    }

    /** @return array<string, array<int, mixed>> */
    protected function rules(): array
    {
        return [
            'escalationIntervalMinutes' => ['required', 'integer', 'min:1', 'max:60'],
            'maxEscalations' => ['required', 'integer', 'min:0', 'max:20'],
            'rateLimitPerWindow' => ['required', 'integer', 'min:1', 'max:10'],
            'rateLimitWindowMinutes' => ['required', 'integer', 'min:1', 'max:120'],
            'rateLimitPerDay' => ['required', 'integer', 'min:1', 'max:100'],
            'misuseThresholdPerWeek' => ['required', 'integer', 'min:1', 'max:50'],
            'callsEnabled' => ['boolean'],
            // Below ~10s nobody can reach a laptop in time; above ~120s the
            // caller has long since given up and the ringing is just noise.
            'callRingSeconds' => ['required', 'integer', 'min:10', 'max:120'],
            'huddlesEnabled' => ['boolean'],
            /*
             * The ceiling is not arbitrary. A huddle is N-squared, so past about
             * a hundred people this box's egress and the Reverb fan-out both
             * stop being credible — and an administrator should not be able to
             * type 500 into a box and find out what that means during an
             * all-hands.
             */
            'huddleMaxParticipants' => ['required', 'integer', 'min:2', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'escalationIntervalMinutes' => 'escalation interval',
            'maxEscalations' => 'maximum escalations',
            'rateLimitPerWindow' => 'emergencies per window',
            'rateLimitWindowMinutes' => 'rate limit window',
            'rateLimitPerDay' => 'emergencies per day',
            'misuseThresholdPerWeek' => 'misuse threshold',
            'callsEnabled' => 'calling',
            'callRingSeconds' => 'ring duration',
            'huddlesEnabled' => 'huddles',
            'huddleMaxParticipants' => 'huddle size limit',
        ];
    }

    public function save(): void
    {
        $this->authorize('manage', User::class);

        $this->validate();

        foreach ($this->settingMap() as $key => $property) {
            Setting::put($key, (int) $this->{$property});
        }

        // Kept out of the integer map above: a boolean cast through (int) would
        // store 1/0 and read back as truthy strings.
        Setting::put('calls.enabled', $this->callsEnabled);
        Setting::put('huddles.enabled', $this->huddlesEnabled);

        $this->statusMessage = 'Settings saved. New emergencies, calls and huddles will use these values.';
    }

    /** @return array<string, string> settings key => component property */
    private function settingMap(): array
    {
        return [
            'emergency.escalation_interval_minutes' => 'escalationIntervalMinutes',
            'emergency.max_escalations' => 'maxEscalations',
            'emergency.rate_limit.per_window' => 'rateLimitPerWindow',
            'emergency.rate_limit.window_minutes' => 'rateLimitWindowMinutes',
            'emergency.rate_limit.per_day' => 'rateLimitPerDay',
            'emergency.misuse_threshold_per_week' => 'misuseThresholdPerWeek',
            'calls.ring_seconds' => 'callRingSeconds',
            'huddles.max_participants' => 'huddleMaxParticipants',
        ];
    }

    public function render()
    {
        return view('livewire.admin.hub-settings');
    }
}
