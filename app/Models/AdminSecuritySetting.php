<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * 后台安全设置模型（键值存储）。
 *
 * 以 key/value 单行一项保存后台安全相关开关（IP 访问控制是否开启、
 * 黑/白名单模式、自动封禁是否开启等）。读取时优先取库值，缺失或
 * 数据库不可用时回退到 defaults()（源自 config('ops.security.*')）。
 */
class AdminSecuritySetting extends Model
{
    protected $fillable = [
        'key',          // 配置项键名（唯一）
        'value',        // 配置值，任意结构，以 JSON 存储
        'description',  // 配置说明（可空）
    ];

    protected function casts(): array
    {
        return [
            // value 可能是布尔/字符串等多种类型，用 json 保留原始结构
            'value' => 'json',
        ];
    }

    // IP 访问控制模式：黑名单 / 白名单
    public const MODES = ['blocklist', 'allowlist'];

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
     * 生成全部安全配置项的默认值（来源于 config('ops.security.*')），供缺库值时回退。
     */
    public static function defaults(): array
    {
        // 非法模式回退为 blocklist，防止配置写坏导致鉴权异常
        $mode = (string) config('ops.security.ip_access.mode', 'blocklist');

        return [
            'ip_access_enabled' => (bool) config('ops.security.ip_access.enabled', false),
            'ip_access_mode' => in_array($mode, self::MODES, true) ? $mode : 'blocklist',
            'auto_ban_enabled' => (bool) config('ops.security.auto_ban.enabled', false),
        ];
    }
}
