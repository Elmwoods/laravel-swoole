<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Ops Center 告警配置项模型（键值存储）。
 *
 * 以 key/value 单行一项的方式保存告警中心的可调参数（通知重复间隔、
 * 自动恢复、升级策略、各通道开关与消息模板、严重级 × 通道路由矩阵等）。
 * 读取时优先取库中值，缺失或数据库不可用时回退到 defaults()（源自 config('ops.alerts.*')）。
 */
class OpsAlertSetting extends Model
{
    protected $fillable = [
        'key',          // 配置项键名（唯一），如 escalation_enabled
        'value',        // 配置值，任意结构（标量/数组），以 JSON 存储
        'description',  // 配置说明（可空）
    ];

    protected function casts(): array
    {
        return [
            // value 可能是布尔/整数/数组等多种类型，用 json 保留原始结构
            'value' => 'json',
        ];
    }

    // 支持的告警严重级别（用于生成路由矩阵）
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

    /**
     * 实例便捷方法：读取指定配置项的值（转调静态 value）。
     */
    public function valueFor(string $key): mixed
    {
        return self::value($key);
    }

    /**
     * 读取单个配置值：库中有则用库值，否则回退默认值；数据库异常时也回退默认。
     */
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

    /**
     * 写入/更新单个配置项（按 key upsert）。
     */
    public static function setValue(string $key, mixed $value, ?string $description = null): void
    {
        self::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'description' => $description],
        );
    }

    /**
     * 返回全部配置：以默认值为底，用库中已存在的键覆盖；数据库异常时返回纯默认。
     */
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

    /**
     * 生成全部配置项的默认值（来源于 config('ops.alerts.*')），供缺库值时回退。
     */
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
