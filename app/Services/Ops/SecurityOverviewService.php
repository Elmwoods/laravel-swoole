<?php

namespace App\Services\Ops;

use App\Models\AdminAuditLog;
use App\Models\AdminIpRule;
use App\Models\AdminLoginEvent;
use App\Models\AdminSession;
use App\Models\AdminTrustedDevice;
use App\Models\AdminUser;
use App\Models\OpsAlert;
use App\Models\OpsChannelHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 安全总览：把 phase 17–26 的安全数据源聚合成一屏态势（纯只读，不改任何既有逻辑）。
 */
class SecurityOverviewService
{
    /**
     * 安全告警来源集合（phase 18/22/25/26）。
     */
    private const SECURITY_SOURCES = ['security_login', 'security_audit', 'security_access', 'channel_health'];

    /**
     * 作用：聚合安全总览一屏所需的全部指标，供前端渲染。
     *
     * @return array 各安全维度指标 + 生成时间戳
     *
     * 「为什么」：纯只读聚合，不触碰任何既有安全逻辑；带 generated_at 便于前端标注数据时点。
     */
    public function overview(): array
    {
        return [
            'generated_at' => now()->toDateTimeString(),
            'login_risk' => $this->loginRisk(),                 // 近 7 天登录风险
            'failed_logins' => $this->failedLogins(),           // 近 24h 失败登录
            'sessions' => $this->sessions(),                    // 活跃会话
            'two_factor' => $this->twoFactor(),                 // 2FA 覆盖率
            'trusted_devices' => $this->trustedDevices(),       // 受信设备
            'security_alerts' => $this->securityAlerts(),       // 未关闭安全告警
            'ip_rules' => $this->ipRules(),                     // IP 规则
            'channel_health' => $this->channelHealth(),         // 通道健康
            'failed_login_trend' => $this->failedLoginTrend(),  // 失败登录趋势
            'recent_events' => $this->recentEvents(),           // 最近安全事件
        ];
    }

    /**
     * 作用：统计近 7 天登录风险——总登录数、新 IP 登录、新设备登录。
     *
     * @return array{window_days:int,total_logins:int,new_ip_logins:int,new_device_logins:int} 登录风险指标
     *
     * 「为什么」：$base 用闭包而非变量复用查询，因为 Eloquent Builder 有状态，
     * 每次调用需新建 query 避免条件互相污染。
     */
    private function loginRisk(): array
    {
        $since = now()->subDays(7);
        // 闭包工厂：每次返回全新的 7 天内登录事件查询，避免链式条件叠加
        $base = fn () => AdminLoginEvent::query()->where('created_at', '>=', $since);

        return [
            'window_days' => 7,
            'total_logins' => $base()->count(),
            'new_ip_logins' => $base()->where('is_new_ip', true)->count(),
            'new_device_logins' => $base()->where('is_new_user_agent', true)->count(),
        ];
    }

    /**
     * 作用：统计近 24 小时失败登录总数与 Top 10 来源 IP。
     *
     * @return array{window_hours:int,total:int,top_ips:array<int,array{ip:string,total:int}>} 失败登录指标
     *
     * 「为什么」：从审计日志按 module=admin.auth + login/login_locked + 结果 failure 过滤；
     * 同样用闭包工厂复用过滤条件，分别做 count 和分组 Top IP。
     */
    private function failedLogins(): array
    {
        $since = now()->subDay();
        // 闭包工厂：近 24h 失败登录审计日志的基础查询
        $base = fn () => AdminAuditLog::query()
            ->where('module', 'admin.auth')
            ->whereIn('action', ['login', 'login_locked'])
            ->where('result', 'failure')
            ->where('created_at', '>=', $since);

        // Top 10 失败来源 IP（排除空 IP）
        $topIps = $base()
            ->whereNotNull('ip_address')
            ->select('ip_address', DB::raw('count(*) as total'))
            ->groupBy('ip_address')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn (object $row): array => ['ip' => (string) $row->ip_address, 'total' => (int) $row->total])
            ->all();

        return [
            'window_hours' => 24,
            'total' => $base()->count(),
            'top_ips' => $topIps,
        ];
    }

    /**
     * 作用：统计当前活跃会话数与去重管理员数。
     *
     * @return array{active:int,distinct_admins:int} 会话指标
     *
     * 「为什么」：revoked_at 为 null 即活跃；用 clone 复用同一基础查询做两次不同聚合，
     * 避免第一次 count 后 Builder 状态影响第二次。
     */
    private function sessions(): array
    {
        $active = AdminSession::query()->whereNull('revoked_at'); // 未撤销 = 活跃

        return [
            'active' => (clone $active)->count(),
            'distinct_admins' => (clone $active)->distinct()->count('admin_user_id'), // 去重管理员数
        ];
    }

    /**
     * 作用：统计活跃管理员的 2FA 开启数与覆盖率。
     *
     * @return array{active_admins:int,enabled:int,coverage_percent:int} 2FA 指标
     *
     * 「为什么」：two_factor_secret 是加密列不能 SQL 过滤，故用 two_factor_confirmed_at
     * 作为 DB 侧代理判断“已开启并确认”；覆盖率对 0 分母做保护返回 0。
     */
    private function twoFactor(): array
    {
        $activeAdmins = AdminUser::query()->where('is_active', true)->count();
        // two_factor_secret 是 encrypted，不能 SQL 过滤；用 two_factor_confirmed_at 作 DB 代理。
        $enabled = AdminUser::query()->where('is_active', true)->whereNotNull('two_factor_confirmed_at')->count();

        return [
            'active_admins' => $activeAdmins,
            'enabled' => $enabled,
            // 覆盖率百分比；分母为 0 时返回 0 避免除零
            'coverage_percent' => $activeAdmins > 0 ? (int) round($enabled / $activeAdmins * 100) : 0,
        ];
    }

    /**
     * 作用：统计当前未过期的受信设备数。
     *
     * @return array{active:int} 受信设备指标
     */
    private function trustedDevices(): array
    {
        return [
            // expires_at 晚于当前时间 = 仍受信
            'active' => AdminTrustedDevice::query()->where('expires_at', '>', now())->count(),
        ];
    }

    /**
     * 作用：统计未关闭（open）的安全告警——总数、按来源分布、按严重度分布。
     *
     * @return array 安全告警统计
     *
     * 「为什么」：只统计 SECURITY_SOURCES 内的告警；by_severity 用 pluck 建映射后逐档兜底 0，
     * 保证 critical/warning/info 三档总是齐全（前端无需判空）。
     */
    private function securityAlerts(): array
    {
        // 闭包工厂：未关闭的安全类告警基础查询
        $open = fn () => OpsAlert::query()->where('status', 'open')->whereIn('source', self::SECURITY_SOURCES);

        $bySeverity = $open()
            ->select('severity', DB::raw('count(*) as total'))
            ->groupBy('severity')
            ->pluck('total', 'severity');

        return [
            'open_total' => $open()->count(),
            'by_source' => $open()
                ->select('source', DB::raw('count(*) as total'))
                ->groupBy('source')
                ->orderByDesc('total')
                ->get()
                ->map(fn (object $row): array => ['source' => (string) $row->source, 'total' => (int) $row->total])
                ->all(),
            'by_severity' => [
                'critical' => (int) ($bySeverity['critical'] ?? 0),
                'warning' => (int) ($bySeverity['warning'] ?? 0),
                'info' => (int) ($bySeverity['info'] ?? 0),
            ],
        ];
    }

    /**
     * 作用：统计生效中的 IP 规则——放行、拦截、自动封禁三类计数。
     *
     * @return array{allow_active:int,deny_active:int,auto_ban_active:int} IP 规则指标
     *
     * 「为什么」：表可能尚未迁移，故先 hasTable 守卫、缺表返回全 0，boot-safe；
     * “生效”定义为 is_active 且（无过期时间 或 未过期），用 $activeScope 复用该条件。
     */
    private function ipRules(): array
    {
        // 表不存在（未迁移）时返回零值兜底，避免查询报错
        if (! Schema::hasTable('admin_ip_rules')) {
            return ['allow_active' => 0, 'deny_active' => 0, 'auto_ban_active' => 0];
        }

        // 生效范围：启用中 且 未过期（永久或过期时间晚于现在）
        $activeScope = fn ($query) => $query
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));

        return [
            'allow_active' => AdminIpRule::query()->where('type', 'allow')->tap($activeScope)->count(),
            'deny_active' => AdminIpRule::query()->where('type', 'deny')->tap($activeScope)->count(),
            // 自动封禁：来源为 auto 的 deny 规则，且必须带有效期（未过期）
            'auto_ban_active' => AdminIpRule::query()
                ->where('type', 'deny')
                ->where('source', 'auto')
                ->where('is_active', true)
                ->where('expires_at', '>', now())
                ->count(),
        ];
    }

    /**
     * 作用：统计告警通道健康状况——healthy/failing/unknown 计数与总数。
     *
     * @return array{healthy:int,failing:int,unknown:int,total:int} 通道健康指标
     *
     * 「为什么」：表可能未迁移，先 hasTable 守卫返回全 0；total 由三档累加而非单独 count，
     * 保证与分档数据一致。
     */
    private function channelHealth(): array
    {
        // 表不存在时零值兜底
        if (! Schema::hasTable('ops_channel_health')) {
            return ['healthy' => 0, 'failing' => 0, 'unknown' => 0, 'total' => 0];
        }

        $counts = OpsChannelHealth::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $healthy = (int) ($counts['healthy'] ?? 0);
        $failing = (int) ($counts['failing'] ?? 0);
        $unknown = (int) ($counts['unknown'] ?? 0);

        return [
            'healthy' => $healthy,
            'failing' => $failing,
            'unknown' => $unknown,
            'total' => $healthy + $failing + $unknown,
        ];
    }

    /**
     * 作用：按天统计近 7 天失败登录趋势。
     *
     * @return array<int, array{date:string,total:int}> 按日期升序的失败登录计数
     *
     * 「为什么」：这里不做零填充，只返回有失败记录的日期（趋势小图可容忍缺日）。
     */
    private function failedLoginTrend(): array
    {
        $since = now()->subDays(7)->startOfDay();

        return AdminAuditLog::query()
            ->where('module', 'admin.auth')
            ->whereIn('action', ['login', 'login_locked'])
            ->where('result', 'failure')
            ->where('created_at', '>=', $since)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as total'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn (object $row): array => ['date' => (string) $row->date, 'total' => (int) $row->total])
            ->all();
    }

    /**
     * 作用：取最近 15 条安全告警事件（不限状态），用于总览的事件流。
     *
     * @return array<int, array{source:string,severity:string,title:string,status:string,at:string|null}> 最近事件
     *
     * 「为什么」：以 last_seen_at 倒序为主、id 倒序兜底（last_seen_at 相同或为空时保证稳定顺序）。
     */
    private function recentEvents(): array
    {
        return OpsAlert::query()
            ->whereIn('source', self::SECURITY_SOURCES)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id') // last_seen_at 相同/空时的稳定次序兜底
            ->limit(15)
            ->get()
            ->map(fn (OpsAlert $alert): array => [
                'source' => $alert->source,
                'severity' => $alert->severity,
                'title' => $alert->title,
                'status' => $alert->status,
                'at' => optional($alert->last_seen_at)->toDateTimeString(),
            ])
            ->all();
    }
}
