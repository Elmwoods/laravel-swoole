<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Throwable;

class OpsAlertSetting extends Model
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

    public const DEFAULT_SEVERITY_CHANNELS = [
        'critical' => ['telegram' => true, 'mail' => true],
        'warning' => ['telegram' => true, 'mail' => true],
        'info' => ['telegram' => true, 'mail' => true],
    ];

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
        return [
            'notification_repeat_minutes' => max(0, (int) config('ops.alerts.thresholds.notification_repeat_minutes', 30)),
            'auto_resolve_enabled' => (bool) config('ops.alerts.thresholds.auto_resolve_enabled', true),
            'auto_resolve_grace_minutes' => max(1, (int) config('ops.alerts.thresholds.auto_resolve_grace_minutes', 5)),
            'severity_channels' => self::DEFAULT_SEVERITY_CHANNELS,
            'telegram_enabled' => true,
            'mail_enabled' => true,
        ];
    }
}
