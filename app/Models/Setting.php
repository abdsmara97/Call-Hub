<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    protected static function booted(): void
    {
        static::saved(fn (Setting $setting) => Cache::forget(self::cacheKey($setting->key)));
        static::deleted(fn (Setting $setting) => Cache::forget(self::cacheKey($setting->key)));
    }

    /** Falls back to config('hub.*') so a fresh install works before seeding. */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever(self::cacheKey($key), function () use ($key, $default) {
            $row = static::query()->where('key', $key)->first();

            return $row ? $row->value['value'] ?? $default : $default;
        });
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => ['value' => $value]]);
    }

    private static function cacheKey(string $key): string
    {
        return "setting:{$key}";
    }
}
