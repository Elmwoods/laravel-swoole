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

    public const SEVERITIES = ['critical', 'warning', 'info'];

    /**
     * 按当前登记的通道动态生成"严重级 × 通道"默认路由矩阵（默认全部允许）。
     */
    public static function defaultSeverityChannels(): array
    {
        $channels = (array) config('ops.alerts.channels', ['telegram', 'mail']);
        $matrix = [];

        foreach (self::SEVERITIES as $severity) {
            foreach ($channels as $channel) {
                $matrix[$severity][$channel] = true;
            }
        }

        return $matrix;
    }

    /**
     * 支持自定义模板的文本通道（全部通道去掉 webhook——webhook 走结构化载荷）。
     *
     * @return array<int, string>
     */
    public static function textChannels(): array
    {
        return array_values(array_diff(
            (array) config('ops.alerts.channels', ['telegram', 'mail']),
            ['webhook'],
        ));
    }

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
        $defaults = [
            'notification_repeat_minutes' => max(0, (int) config('ops.alerts.thresholds.notification_repeat_minutes', 30)),
            'auto_resolve_enabled' => (bool) config('ops.alerts.thresholds.auto_resolve_enabled', true),
            'auto_resolve_grace_minutes' => max(1, (int) config('ops.alerts.thresholds.auto_resolve_grace_minutes', 5)),
            'escalation_enabled' => (bool) config('ops.alerts.thresholds.escalation_enabled', true),
            'escalation_after_minutes' => max(1, (int) config('ops.alerts.thresholds.escalation_after_minutes', 30)),
            'message_template' => (string) config('ops.alerts.message_template', ''),
            'severity_channels' => self::defaultSeverityChannels(),
        ];

        foreach ((array) config('ops.alerts.channels', ['telegram', 'mail']) as $channel) {
            $defaults["{$channel}_enabled"] = true;
        }

        // 每通道独立模板（仅文本通道，webhook 走结构化载荷不套模板）。留空=回退全局 message_template。
        foreach (self::textChannels() as $channel) {
            $defaults["message_template_{$channel}"] = (string) config("ops.alerts.{$channel}.message_template", '');
        }

        return $defaults;
    }
}
