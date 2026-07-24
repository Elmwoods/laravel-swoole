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

    public function overview(): array
    {
        return [
            'generated_at' => now()->toDateTimeString(),
            'login_risk' => $this->loginRisk(),
            'failed_logins' => $this->failedLogins(),
            'sessions' => $this->sessions(),
            'two_factor' => $this->twoFactor(),
            'trusted_devices' => $this->trustedDevices(),
            'security_alerts' => $this->securityAlerts(),
            'ip_rules' => $this->ipRules(),
            'channel_health' => $this->channelHealth(),
            'failed_login_trend' => $this->failedLoginTrend(),
            'recent_events' => $this->recentEvents(),
        ];
    }

    private function loginRisk(): array
    {
        $since = now()->subDays(7);
        $base = fn () => AdminLoginEvent::query()->where('created_at', '>=', $since);

        return [
            'window_days' => 7,
            'total_logins' => $base()->count(),
            'new_ip_logins' => $base()->where('is_new_ip', true)->count(),
            'new_device_logins' => $base()->where('is_new_user_agent', true)->count(),
        ];
    }

    private function failedLogins(): array
    {
        $since = now()->subDay();
        $base = fn () => AdminAuditLog::query()
            ->where('module', 'admin.auth')
            ->whereIn('action', ['login', 'login_locked'])
            ->where('result', 'failure')
            ->where('created_at', '>=', $since);

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

    private function sessions(): array
    {
        $active = AdminSession::query()->whereNull('revoked_at');

        return [
            'active' => (clone $active)->count(),
            'distinct_admins' => (clone $active)->distinct()->count('admin_user_id'),
        ];
    }

    private function twoFactor(): array
    {
        $activeAdmins = AdminUser::query()->where('is_active', true)->count();
        // two_factor_secret 是 encrypted，不能 SQL 过滤；用 two_factor_confirmed_at 作 DB 代理。
        $enabled = AdminUser::query()->where('is_active', true)->whereNotNull('two_factor_confirmed_at')->count();

        return [
            'active_admins' => $activeAdmins,
            'enabled' => $enabled,
            'coverage_percent' => $activeAdmins > 0 ? (int) round($enabled / $activeAdmins * 100) : 0,
        ];
    }

    private function trustedDevices(): array
    {
        return [
            'active' => AdminTrustedDevice::query()->where('expires_at', '>', now())->count(),
        ];
    }

    private function securityAlerts(): array
    {
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

    private function ipRules(): array
    {
        if (! Schema::hasTable('admin_ip_rules')) {
            return ['allow_active' => 0, 'deny_active' => 0, 'auto_ban_active' => 0];
        }

        $activeScope = fn ($query) => $query
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));

        return [
            'allow_active' => AdminIpRule::query()->where('type', 'allow')->tap($activeScope)->count(),
            'deny_active' => AdminIpRule::query()->where('type', 'deny')->tap($activeScope)->count(),
            'auto_ban_active' => AdminIpRule::query()
                ->where('type', 'deny')
                ->where('source', 'auto')
                ->where('is_active', true)
                ->where('expires_at', '>', now())
                ->count(),
        ];
    }

    private function channelHealth(): array
    {
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

    private function recentEvents(): array
    {
        return OpsAlert::query()
            ->whereIn('source', self::SECURITY_SOURCES)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
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
