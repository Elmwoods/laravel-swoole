<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * 后台管理员登录限流 / 暴力破解防护服务。
 *
 * 归属于 admin 安全子系统的「登录风控」环节：基于 Laravel RateLimiter，
 * 以「邮箱 + IP」为维度统计失败登录次数，超过阈值即锁定一段时间，
 * 用来抵御针对后台账号的暴力破解与撞库尝试。
 *
 * 关键参数：
 *  - MAX_ATTEMPTS  = 5    锁定前允许的最大失败次数
 *  - DECAY_SECONDS = 900  单次计数的存活/锁定窗口（15 分钟）
 */
class AdminLoginThrottleService
{
    // 触发锁定前允许的最大失败尝试次数
    private const MAX_ATTEMPTS = 5;

    // 计数衰减 / 锁定窗口，单位秒（900 秒 = 15 分钟）
    private const DECAY_SECONDS = 900;

    /**
     * 作用：为「邮箱 + IP」组合生成稳定且不泄露原文的限流计数键。
     *
     * @param  string  $email  登录邮箱（大小写、首尾空格会被归一）
     * @param  string  $ip  来源 IP
     * @return string RateLimiter 使用的键
     *
     * 为什么归一化并 sha1：先小写去空格避免同一账号因大小写/空格被算作不同键而绕过限流；
     * 再对「邮箱|IP」做 sha1，避免把明文邮箱写进缓存键，同时得到定长安全的键名。
     */
    public function key(string $email, string $ip): string
    {
        $normalizedEmail = Str::lower(trim($email));

        return 'admin-login:'.sha1($normalizedEmail.'|'.$ip);
    }

    /**
     * 作用：判断该邮箱+IP 是否已达失败上限（即当前是否处于锁定状态）。
     *
     * @param  string  $email  登录邮箱
     * @param  string  $ip  来源 IP
     * @return bool true 表示已超过 MAX_ATTEMPTS，应拒绝本次登录
     */
    public function tooManyAttempts(string $email, string $ip): bool
    {
        return RateLimiter::tooManyAttempts($this->key($email, $ip), self::MAX_ATTEMPTS);
    }

    /**
     * 作用：登录失败时对该邮箱+IP 计数加一，并刷新衰减窗口。
     *
     * @param  string  $email  登录邮箱
     * @param  string  $ip  来源 IP
     * @return int 计数自增后的当前失败次数
     *
     * 为什么传入 DECAY_SECONDS：每次命中都以 15 分钟为窗口，只有在窗口内累计
     * 达到上限才锁定；窗口过后计数自动衰减，实现「短时高频才封、偶发失败自愈」。
     */
    public function hit(string $email, string $ip): int
    {
        return RateLimiter::hit($this->key($email, $ip), self::DECAY_SECONDS);
    }

    /**
     * 作用：清空该邮箱+IP 的失败计数（通常在登录成功后调用以立即解锁）。
     *
     * @param  string  $email  登录邮箱
     * @param  string  $ip  来源 IP
     */
    public function clear(string $email, string $ip): void
    {
        RateLimiter::clear($this->key($email, $ip));
    }

    /**
     * 作用：返回距离该邮箱+IP 解锁还需等待的秒数。
     *
     * @param  string  $email  登录邮箱
     * @param  string  $ip  来源 IP
     * @return int 剩余锁定秒数（用于给前端提示「请 N 秒后重试」）
     */
    public function availableIn(string $email, string $ip): int
    {
        return RateLimiter::availableIn($this->key($email, $ip));
    }
}
