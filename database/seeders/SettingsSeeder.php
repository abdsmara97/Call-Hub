<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'emergency.escalation_interval_minutes' => config('hub.emergency.escalation_interval_minutes'),
            'emergency.max_escalations' => config('hub.emergency.max_escalations'),
            'emergency.rate_limit.per_window' => config('hub.emergency.rate_limit.per_window'),
            'emergency.rate_limit.window_minutes' => config('hub.emergency.rate_limit.window_minutes'),
            'emergency.rate_limit.per_day' => config('hub.emergency.rate_limit.per_day'),
            'emergency.misuse_threshold_per_week' => config('hub.emergency.misuse_threshold_per_week'),
        ];

        foreach ($defaults as $key => $value) {
            if (! Setting::query()->where('key', $key)->exists()) {
                Setting::put($key, $value);
            }
        }
    }
}
