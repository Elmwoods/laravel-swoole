<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Throwable;

class AdminSecuritySetting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    public const MODES = ['blocklist', 'allowlist'];

    public function valueFor(string $key): mixed
    {
        return self::value($key);
    }

    public static function value(string $key): mixed
    {
        try {
            $setting = self::query()->where('key', $key)->first();
        } catch (Throwable) {
            return self::defaults()[$key] ?? null;
        }

        if ($setting !== null) {
            return $setting->value;
        }

        return self::defaults()[$key] ?? null;
    }

    public static function setValue(string $key, mixed $value, ?string $description = null): void
    {
        self::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'description' => $description],
        );
    }

    public static function allValues(): array
    {
        $values = self::defaults();

        try {
            self::query()
                ->get()
                ->each(function (self $setting) use (&$values): void {
                    if (array_key_exists($setting->key, $values)) {
                        $values[$setting->key] = $setting->value;
                    }
                });
        } catch (Throwable) {
            return $values;
        }

        return $values;
    }

    public static function defaults(): array
    {
        $mode = (string) config('ops.security.ip_access.mode', 'blocklist');

        return [
            'ip_access_enabled' => (bool) config('ops.security.ip_access.enabled', false),
            'ip_access_mode' => in_array($mode, self::MODES, true) ? $mode : 'blocklist',
        ];
    }
}
