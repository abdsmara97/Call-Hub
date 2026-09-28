<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        // Settings are per-tenant rows; seed them for the default tenant so a
        // fresh install's admin screen shows saved values, not just fallbacks.
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'default'],
            ['name' => config('app.name')],
        );

        app(TenantContext::class)->runAs($tenant, fn () => $this->seedDefaults());
    }

    private function seedDefaults(): void
    {
        $defaults = [
            'emergency.escalation_interval_minutes' => config('hub.emergency.escalation_interval_minutes'),
            'emergency.max_escalations' => config('hub.emergency.max_escalations'),
            'emergency.rate_limit.per_window' => config('hub.emergency.rate_limit.per_window'),
            'emergency.rate_limit.window_minutes' => config('hub.emergency.rate_limit.window_minutes'),
            'emergency.rate_limit.per_day' => config('hub.emergency.rate_limit.per_day'),
            'emergency.misuse_threshold_per_week' => config('hub.emergency.misuse_threshold_per_week'),
            'calls.enabled' => config('hub.calls.enabled'),
            'calls.ring_seconds' => config('hub.calls.ring_seconds'),
            'huddles.enabled' => config('hub.huddles.enabled'),
            'huddles.max_participants' => config('hub.huddles.max_participants'),
        ];

        foreach ($defaults as $key => $value) {
            if (! Setting::query()->where('key', $key)->exists()) {
                Setting::put($key, $value);
            }
        }
    }
}
