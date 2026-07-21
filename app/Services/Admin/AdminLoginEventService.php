<?php

namespace App\Services\Admin;

use App\Models\AdminLoginEvent;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminLoginEventService
{
    /**
     * 记录一次成功登录，并据历史判断是否为新 IP / 新设备。
     *
     * 判定只对比该管理员既往登录事件；首登（无历史）视为新 IP/新设备。
     */
    public function record(AdminUser $admin, Request $request, bool $trusted = false): AdminLoginEvent
    {
        $ip = $request->ip();
        $userAgent = $this->userAgentSummary($request->userAgent());

        $isNewIp = $ip === null
            || ! AdminLoginEvent::query()
                ->where('admin_user_id', $admin->id)
                ->where('ip_address', $ip)
                ->exists();

        $isNewUserAgent = $userAgent === ''
            ? false
            : ! AdminLoginEvent::query()
                ->where('admin_user_id', $admin->id)
                ->where('user_agent', $userAgent)
                ->exists();

        return AdminLoginEvent::query()->create([
            'admin_user_id' => $admin->id,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'trusted' => $trusted,
            'is_new_ip' => $isNewIp,
            'is_new_user_agent' => $isNewUserAgent,
        ]);
    }

    /**
     * 返回某管理员最近 N 条登录历史（时间倒序）。
     */
    public function history(AdminUser $admin, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        return AdminLoginEvent::query()
            ->where('admin_user_id', $admin->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (AdminLoginEvent $event): array => [
                'id' => $event->id,
                'ip_address' => $event->ip_address,
                'user_agent' => $event->user_agent,
                'trusted' => $event->trusted,
                'is_new_ip' => $event->is_new_ip,
                'is_new_user_agent' => $event->is_new_user_agent,
                'created_at' => optional($event->created_at)->toDateTimeString(),
            ])
            ->all();
    }

    public function userAgentSummary(?string $userAgent): string
    {
        $summary = (string) $userAgent;
        $summary = preg_replace('/(token|password|authorization|cookie)=([^;\s]+)/i', '$1=[FILTERED]', $summary) ?? $summary;
        $summary = preg_replace('/[\r\n\t]+/', ' ', $summary) ?? $summary;

        return Str::limit($summary, 180, '');
    }
}
