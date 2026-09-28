<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Thin reader over the runtime settings table with config/hub.php as the
 * fallback, so the app is fully functional before an admin ever opens Settings.
 */
class HubSettings
{
    public function escalationIntervalMinutes(): int
    {
        return (int) Setting::get(
            'emergency.escalation_interval_minutes',
            config('hub.emergency.escalation_interval_minutes')
        );
    }

    public function maxEscalations(): int
    {
        return (int) Setting::get(
            'emergency.max_escalations',
            config('hub.emergency.max_escalations')
        );
    }

    public function rateLimitPerWindow(): int
    {
        return (int) Setting::get(
            'emergency.rate_limit.per_window',
            config('hub.emergency.rate_limit.per_window')
        );
    }

    public function rateLimitWindowMinutes(): int
    {
        return (int) Setting::get(
            'emergency.rate_limit.window_minutes',
            config('hub.emergency.rate_limit.window_minutes')
        );
    }

    public function rateLimitPerDay(): int
    {
        return (int) Setting::get(
            'emergency.rate_limit.per_day',
            config('hub.emergency.rate_limit.per_day')
        );
    }

    public function misuseThresholdPerWeek(): int
    {
        return (int) Setting::get(
            'emergency.misuse_threshold_per_week',
            config('hub.emergency.misuse_threshold_per_week')
        );
    }

    /**
     * The kill switch for 1:1 calling. Runtime-settable so an administrator can
     * turn calls off during an incident without waiting for a deploy.
     */
    public function callsEnabled(): bool
    {
        return (bool) Setting::get('calls.enabled', config('hub.calls.enabled'));
    }

    public function callRingSeconds(): int
    {
        return (int) Setting::get('calls.ring_seconds', config('hub.calls.ring_seconds'));
    }

    /**
     * The kill switch for huddles, deliberately separate from callsEnabled().
     *
     * Huddle media passes through this server; 1:1 call media does not. During a
     * bandwidth or CPU incident an administrator needs to stop the expensive
     * thing without also taking away the free peer-to-peer one.
     */
    public function huddlesEnabled(): bool
    {
        return (bool) Setting::get('huddles.enabled', config('hub.huddles.enabled'));
    }

    /**
     * A huddle is N-squared: everyone subscribes to everyone. This is the lever
     * that bounds what one room can cost in egress.
     */
    public function huddleMaxParticipants(): int
    {
        return (int) Setting::get('huddles.max_participants', config('hub.huddles.max_participants'));
    }

    /** @return array<string, int|bool> */
    public function all(): array
    {
        return [
            'emergency.escalation_interval_minutes' => $this->escalationIntervalMinutes(),
            'emergency.max_escalations' => $this->maxEscalations(),
            'emergency.rate_limit.per_window' => $this->rateLimitPerWindow(),
            'emergency.rate_limit.window_minutes' => $this->rateLimitWindowMinutes(),
            'emergency.rate_limit.per_day' => $this->rateLimitPerDay(),
            'emergency.misuse_threshold_per_week' => $this->misuseThresholdPerWeek(),
            'calls.enabled' => $this->callsEnabled(),
            'calls.ring_seconds' => $this->callRingSeconds(),
            'huddles.enabled' => $this->huddlesEnabled(),
            'huddles.max_participants' => $this->huddleMaxParticipants(),
        ];
    }
}
