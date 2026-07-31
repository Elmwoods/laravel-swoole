<?php

namespace App\Services\Admin;

use App\Models\AdminLoginEvent;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * 后台管理员登录事件记录服务。
 *
 * 归属于 admin 安全子系统的「登录审计」环节：每次管理员成功登录都会落一条
 * AdminLoginEvent 审计记录，并结合历史判定本次是否来自新 IP / 新设备（User-Agent），
 * 供安全总览、异常登录提醒、风控展示等功能消费。
 *
 * User-Agent 在入库前会经脱敏与截断处理，避免把敏感 token、超长串写进审计表。
 */
class AdminLoginEventService
{
    /**
     * 记录一次成功登录，并据历史判断是否为新 IP / 新设备。
     *
     * 判定只对比该管理员既往登录事件；首登（无历史）视为新 IP/新设备。
     *
     * 作用：写入一条登录审计事件，并计算 is_new_ip / is_new_user_agent 标记。
     *
     * @param  AdminUser  $admin  本次登录的管理员
     * @param  Request  $request  当前请求，用于取 IP 与 User-Agent
     * @param  bool  $trusted  本次是否走了受信任设备（免二次验证）通道
     * @return AdminLoginEvent 新建的登录事件记录
     *
     * 为什么按管理员维度比对历史：新 IP / 新设备是相对「该管理员本人」而言的异常信号，
     * 全局比对没有意义；首次登录无历史，按定义即视为新 IP、新设备。
     */
    public function record(AdminUser $admin, Request $request, bool $trusted = false): AdminLoginEvent
    {
        $ip = $request->ip();
        // User-Agent 先脱敏截断，后续既用于比对也用于入库，保持一致
        $userAgent = $this->userAgentSummary($request->userAgent());

        // 无法取得 IP，或历史中从未出现过该 IP → 视为新 IP
        $isNewIp = $ip === null
            || ! AdminLoginEvent::query()
                ->where('admin_user_id', $admin->id)
                ->where('ip_address', $ip)
                ->exists();

        // UA 为空时不作为「新设备」信号（避免脱敏后空串误报）；否则历史无此 UA 即为新设备
        $isNewUserAgent = $userAgent === ''
            ? false
            : ! AdminLoginEvent::query()
                ->where('admin_user_id', $admin->id)
                ->where('user_agent', $userAgent)
                ->exists();

        // 落库这条审计事件；trusted 标记本次是否经受信任设备免验证登录
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
     *
     * 作用：查询并整形该管理员的近期登录事件，供前端安全页/个人中心展示。
     *
     * @param  AdminUser  $admin  目标管理员
     * @param  int  $limit  返回条数，最终会被夹取到 1~100
     * @return array 登录事件的数组，每项为纯数组（已去除模型对象）
     */
    public function history(AdminUser $admin, int $limit = 20): array
    {
        // 夹取分页大小到 [1,100]，防止调用方传入 0/负数/超大值拖垮查询
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

    /**
     * 作用：把原始 User-Agent 脱敏并规范化为可安全入库/展示的摘要串。
     *
     * @param  string|null  $userAgent  原始 User-Agent（可能为 null）
     * @return string 脱敏、去换行并截断后的 UA 摘要
     *
     * 为什么要脱敏：某些代理/客户端会把 token、password、cookie 等敏感信息塞进 UA；
     * 直接写入审计表会造成敏感数据泄露，故先做键值过滤，再压平换行、限制长度。
     */
    public function userAgentSummary(?string $userAgent): string
    {
        $summary = (string) $userAgent;
        // 过滤 UA 里可能夹带的敏感键值（token/password/authorization/cookie），替换为占位符
        $summary = preg_replace('/(token|password|authorization|cookie)=([^;\s]+)/i', '$1=[FILTERED]', $summary) ?? $summary;
        // 压平回车/换行/制表符为空格，防止日志换行注入并保证单行存储
        $summary = preg_replace('/[\r\n\t]+/', ' ', $summary) ?? $summary;

        // 限长 180 字符，避免异常超长 UA 撑爆字段
        return Str::limit($summary, 180, '');
    }
}
