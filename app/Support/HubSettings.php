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

    /** @return array<string, int> */
    public function all(): array
    {
        return [
            'emergency.escalation_interval_minutes' => $this->escalationIntervalMinutes(),
            'emergency.max_escalations' => $this->maxEscalations(),
            'emergency.rate_limit.per_window' => $this->rateLimitPerWindow(),
            'emergency.rate_limit.window_minutes' => $this->rateLimitWindowMinutes(),
            'emergency.rate_limit.per_day' => $this->rateLimitPerDay(),
            'emergency.misuse_threshold_per_week' => $this->misuseThresholdPerWeek(),
        ];
    }
}
