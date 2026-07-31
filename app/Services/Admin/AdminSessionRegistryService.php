<?php

namespace App\Services\Admin;

use App\Models\AdminSession;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * 管理端会话注册表服务。
 *
 * 隶属后台安全 / 认证子系统，维护 admin_sessions 表——每个登录会话对应一行，
 * 存储会话 token 的 sha256、设备标签、IP、UA 摘要与活跃时间。核心思路：随机
 * token 只存放在服务端会话袋中，数据库仅保存其哈希，因而客户端无法伪造会话标识；
 * 由此支撑「查看在线设备」「远程撤销某会话 / 其他所有会话」「登出清理」等能力，
 * 并作为中间件的会话有效性强制点（被撤销即登出）。
 */
class AdminSessionRegistryService
{
    // 会话袋中存放随机会话 token 的键名
    public const TOKEN_KEY = 'admin_session_token';

    /**
     * 作用：注入登录事件服务，用于生成 UA 摘要。
     *
     * @param  AdminLoginEventService  $loginEvents  登录事件 / UA 归纳服务
     */
    public function __construct(private readonly AdminLoginEventService $loginEvents) {}

    /**
     * 登录时注册一个新会话：生成随机 token 放进会话袋，注册表只存其 sha256。
     *
     * token 随会话数据走（不受 session id 重生成影响），是可靠且不可被客户端伪造的会话标识。
     */
    public function register(AdminUser $admin, Request $request): AdminSession
    {
        // 生成 40 位随机 token，仅存入服务端会话袋（客户端不可见）
        $token = Str::random(40);
        $request->session()->put(self::TOKEN_KEY, $token);

        // 注册表内只落 token 的 sha256，实现不可伪造的会话标识
        return $this->persist($admin, $request, $token);
    }

    /**
     * 中间件强制点：当前会话被撤销 → false（调用方登出）；
     * 会话袋无 token（部署前旧会话）→ 懒注册；正常 → 刷新活跃时间。
     */
    public function ensureActive(AdminUser $admin, Request $request): bool
    {
        $token = $request->session()->get(self::TOKEN_KEY);

        // 会话袋无 token（多为本功能上线前建立的旧会话）→ 懒注册一条
        if (! is_string($token) || $token === '') {
            $this->register($admin, $request);

            return true;
        }

        // 按 token 哈希定位注册表行
        $session = AdminSession::query()
            ->where('session_token_hash', $this->hash($token))
            ->first();

        // 该会话已被撤销 → 返回 false，由调用方执行登出
        if ($session !== null && $session->revoked_at !== null) {
            return false;
        }

        // 正常路径：刷新活跃时间等字段
        $this->persist($admin, $request, $token);

        return true;
    }

    /**
     * 本人活跃会话列表（不含 hash），标记当前会话。
     */
    public function list(AdminUser $admin, Request $request): array
    {
        $currentHash = $this->currentHash($request);

        return AdminSession::query()
            ->where('admin_user_id', $admin->id)
            ->whereNull('revoked_at')
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AdminSession $session): array => [
                'id' => $session->id,
                'label' => $session->label,
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                // 用常量时间比较标记哪一条是当前会话，避免时序侧信道
                'current' => $currentHash !== null && hash_equals($currentHash, (string) $session->session_token_hash),
                'last_activity_at' => optional($session->last_activity_at)->toDateTimeString(),
                'created_at' => optional($session->created_at)->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * 撤销本人一台会话（软标记），返回是否命中。
     */
    public function revoke(AdminUser $admin, int $sessionId): bool
    {
        // 限定 admin_user_id 确保只能撤销本人会话；软标记 revoked_at 而非删除
        return AdminSession::query()
            ->where('admin_user_id', $admin->id)
            ->whereKey($sessionId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]) > 0;
    }

    /**
     * 撤销本人除当前会话外的所有活跃会话，返回撤销数量。
     */
    public function revokeOthers(AdminUser $admin, Request $request): int
    {
        $query = AdminSession::query()
            ->where('admin_user_id', $admin->id)
            ->whereNull('revoked_at');

        $currentHash = $this->currentHash($request);

        // 排除当前会话，避免把自己也一并撤销
        if ($currentHash !== null) {
            $query->where('session_token_hash', '!=', $currentHash);
        }

        return $query->update(['revoked_at' => now()]);
    }

    /**
     * 登出时清理当前会话行。
     */
    public function forget(Request $request): void
    {
        $currentHash = $this->currentHash($request);

        if ($currentHash === null) {
            return;
        }

        AdminSession::query()->where('session_token_hash', $currentHash)->delete();
    }

    /**
     * 作用：以 token 哈希为唯一键，创建或更新注册表行并刷新活跃时间。
     *
     * @param  AdminUser  $admin  会话所属管理员
     * @param  Request  $request  当前请求（提供 IP / UA）
     * @param  string  $token  明文会话 token
     * @return AdminSession 持久化后的会话行
     *
     * 为什么：以 session_token_hash 为幂等键 updateOrCreate——同一 token 反复请求只更新不重复插入；
     * 顺带把 revoked_at 复位为 null，允许被撤销后又重新活跃的会话恢复（正常路径不会命中已撤销行）。
     */
    private function persist(AdminUser $admin, Request $request, string $token): AdminSession
    {
        return AdminSession::query()->updateOrCreate(
            // 幂等键：token 哈希；同一会话只维护一行
            ['session_token_hash' => $this->hash($token)],
            [
                'admin_user_id' => $admin->id,
                'label' => $this->deviceLabel($request->userAgent()),
                'ip_address' => $request->ip(),
                // UA 原文过长，存归纳后的摘要
                'user_agent' => $this->loginEvents->userAgentSummary($request->userAgent()),
                'last_activity_at' => now(),
                'revoked_at' => null,
            ],
        );
    }

    /**
     * 作用：取出当前请求所属会话的 token 哈希。
     *
     * @param  Request  $request  当前请求
     * @return string|null token 哈希，缺失 token 时为 null
     *
     * 为什么：撤销 / 标记「当前会话」等操作都以哈希为比对依据，明文 token 不出会话袋。
     */
    private function currentHash(Request $request): ?string
    {
        $token = $request->session()->get(self::TOKEN_KEY);

        return is_string($token) && $token !== '' ? $this->hash($token) : null;
    }

    /**
     * 作用：计算会话 token 的 sha256 哈希。
     *
     * @param  string  $token  明文会话 token
     * @return string sha256 十六进制哈希
     *
     * 为什么：数据库只存哈希，即便注册表泄露也无法还原出可用的会话 token。
     */
    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * 作用：从 User-Agent 粗略解析出「浏览器 · 平台」的可读设备标签。
     *
     * @param  string|null  $userAgent  请求的 User-Agent
     * @return string 形如「Chrome · macOS」的标签
     *
     * 为什么：仅供在线设备列表展示，用简单包含匹配即可；注意 Edge(Edg) 需先于 Chrome 判断，
     * 因为 Edge 的 UA 同时含有 Chrome 字样。
     */
    private function deviceLabel(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        $platform = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Unknown',
        };

        $browser = match (true) {
            // Edge 的 UA 含 Chrome，必须先匹配 'Edg' 才不会被误判为 Chrome
            str_contains($ua, 'Edg') => 'Edge',
            str_contains($ua, 'Chrome') => 'Chrome',
            str_contains($ua, 'Firefox') => 'Firefox',
            // Safari 放最后：Chrome 的 UA 也含 Safari 字样，须让 Chrome 先命中
            str_contains($ua, 'Safari') => 'Safari',
            default => 'Browser',
        };

        return "{$browser} · {$platform}";
    }
}
