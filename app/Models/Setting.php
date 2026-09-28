<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    protected static function booted(): void
    {
        // A write flushes both the row's tenant slot and the unbound-context
        // slot: console reads cache under 'global' while the row itself
        // belongs to a tenant, and a stale entry in either is a wrong answer.
        $flush = function (Setting $setting): void {
            Cache::forget(self::cacheKey($setting->key, $setting->tenant_id));
            Cache::forget(self::cacheKey($setting->key, null));
        };

        static::saved($flush);
        static::deleted($flush);
    }

    /** Falls back to config('hub.*') so a fresh install works before seeding. */
    public static function get(string $key, mixed $default = null): mixed
    {
        $tenantId = app(TenantContext::class)->id();

        return Cache::rememberForever(self::cacheKey($key, $tenantId), function () use ($key, $default) {
            $row = static::query()->where('key', $key)->first();

            return $row ? $row->value['value'] ?? $default : $default;
        });
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => ['value' => $value]]);
    }

    /**
     * Settings are per-tenant rows now, so the cache entry carries the tenant
     * id; an unbound context (console, seeders) gets its own slot.
     */
    private static function cacheKey(string $key, ?int $tenantId): string
    {
        return 'setting:'.($tenantId ?? 'global').":{$key}";
    }
}
