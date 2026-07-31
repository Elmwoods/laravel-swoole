<?php

namespace App\Services\Admin;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;

/**
 * 管理端会话空闲超时服务。
 *
 * 隶属后台安全 / 认证子系统，负责基于「最近活动时间」的空闲超时判定：
 * 每次请求在会话袋中刷新时间戳，超过 120 分钟无活动即视为超时，由中间件据此
 * 强制登出。所有时间比较均支持注入 $now，便于测试确定性。
 */
class AdminSessionSecurityService
{
    // 会话袋中存放「最近活动时间戳」的键名
    public const LAST_ACTIVITY_SESSION_KEY = 'admin_last_activity_at';

    // 空闲超时阈值（分钟）
    public const IDLE_TIMEOUT_MINUTES = 120;

    // 空闲超时阈值（秒），由分钟阈值换算，供时间戳差值直接比较
    public const IDLE_TIMEOUT_SECONDS = self::IDLE_TIMEOUT_MINUTES * 60;

    /**
     * 作用：把当前（或指定）时刻写入会话袋，刷新最近活动时间。
     *
     * @param  Request  $request  当前请求（携带会话）
     * @param  CarbonInterface|null  $now  指定时刻，null 表示当前时间
     * @return void
     *
     * 为什么：$now 可注入，便于测试中固定时间；存 Unix 时间戳而非对象，读写更轻量。
     */
    public function touch(Request $request, ?CarbonInterface $now = null): void
    {
        $request->session()->put(self::LAST_ACTIVITY_SESSION_KEY, ($now ?? now())->timestamp);
    }

    /**
     * 作用：从会话袋读取最近活动时间戳。
     *
     * @param  Request  $request  当前请求
     * @return int|null 时间戳，缺失或非数值返回 null
     *
     * 为什么：做数值校验，避免会话中残留的异常值被当作时间戳误用。
     */
    public function lastActivityAt(Request $request): ?int
    {
        $value = $request->session()->get(self::LAST_ACTIVITY_SESSION_KEY);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * 作用：判定给定的最近活动时间是否已触发空闲超时。
     *
     * @param  int|null  $lastActivityAt  最近活动时间戳
     * @param  CarbonInterface|null  $now  当前时刻，null 表示现在
     * @return bool 超过阈值返回 true
     *
     * 为什么：无记录（null）时不判超时，避免全新会话被误判；用秒级差值与阈值比较。
     */
    public function isIdleTimedOut(?int $lastActivityAt, ?CarbonInterface $now = null): bool
    {
        // 尚无活动记录（如刚建立的会话）不视为超时
        if ($lastActivityAt === null) {
            return false;
        }

        // 当前时间与最近活动的秒级差值超过阈值即判超时
        return (($now ?? now())->timestamp - $lastActivityAt) > self::IDLE_TIMEOUT_SECONDS;
    }
}
