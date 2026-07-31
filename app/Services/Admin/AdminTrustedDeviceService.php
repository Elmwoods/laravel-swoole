<?php

namespace App\Services\Admin;

use App\Models\AdminTrustedDevice;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * 后台管理员受信任设备管理服务。
 *
 * 归属于 admin 安全子系统的「受信任设备 / 二次验证记忆」环节：管理员在某设备上
 * 通过完整验证（如 2FA）后可将其标记为受信任，随后一段时间内该设备免二次验证。
 *
 * 安全模型：
 *  - 服务端只保存 token 的 sha256 摘要（token_hash），明文 token 仅存在于客户端 cookie；
 *    即便数据库泄露也无法反推出可用 token，校验时以「对入参再次哈希后比对」完成。
 *  - 设备带过期时间（LIFETIME_DAYS 天），并记录 label/IP/UA 便于用户识别与撤销。
 */
class AdminTrustedDeviceService
{
    // 存放受信任设备明文 token 的 cookie 名
    public const COOKIE_NAME = 'admin_trusted_device';

    // 受信任设备有效期（天）
    public const LIFETIME_DAYS = 30;

    /**
     * 为管理员签发一台受信任设备，返回放入 cookie 的明文 token。
     *
     * 仅存 token 的 sha256 摘要，明文只在 cookie 中，服务端不可反查。
     *
     * 作用：生成随机 token、按摘要落库一条受信任设备记录，并把明文 token 交回调用方写入 cookie。
     *
     * @param  AdminUser  $admin  要为其签发受信任设备的管理员
     * @param  Request  $request  当前请求，用于记录设备标签、IP 与 User-Agent
     * @return string 明文 token（仅此一次返回，之后无法从服务端取回）
     *
     * 为什么返回明文却只存摘要：这是「不可逆存储」的凭据设计——明文只随响应写入客户端 cookie，
     * 服务端仅留 sha256 摘要用于后续比对，从根本上避免服务端持有可被盗用的明文凭据。
     */
    public function issue(AdminUser $admin, Request $request): string
    {
        // 64 位随机 token，作为设备凭据；足够长以抵抗暴力猜测
        $token = Str::random(64);

        AdminTrustedDevice::query()->create([
            'admin_user_id' => $admin->id,
            // 只入库 sha256 摘要，明文 token 不落库
            'token_hash' => $this->hash($token),
            // 从 UA 提炼出「浏览器 · 平台」标签，方便用户在设备列表中辨认
            'label' => $this->deviceLabel($request->userAgent()),
            'last_ip' => $request->ip(),
            'last_user_agent' => $this->userAgentSummary($request->userAgent()),
            // 设定过期时间，超期即失效需重新验证
            'expires_at' => now()->addDays(self::LIFETIME_DAYS),
        ]);

        return $token;
    }

    /**
     * 校验管理员的受信任设备 token，命中且未过期才返回设备。
     *
     * 作用：根据 cookie 中的明文 token 查找该管理员名下的有效受信任设备。
     *
     * @param  AdminUser  $admin  当前管理员
     * @param  string|null  $token  客户端 cookie 中的明文 token（可能缺失）
     * @return AdminTrustedDevice|null 命中且未过期时返回设备，否则返回 null
     *
     * 为什么用 hash($token) 查询：库中存的是摘要，故以「对入参同法哈希后匹配 token_hash」
     * 完成校验，既能验证凭据又不必也无法还原明文。
     */
    public function findValid(AdminUser $admin, ?string $token): ?AdminTrustedDevice
    {
        // 缺失或空 token 直接判定不受信任
        if (! is_string($token) || $token === '') {
            return null;
        }

        // 按 管理员 + token 摘要 定位设备（限定 admin_user_id 防止跨账号盗用他人 token）
        $device = AdminTrustedDevice::query()
            ->where('admin_user_id', $admin->id)
            ->where('token_hash', $this->hash($token))
            ->first();

        // 未命中或已过期都视为无效
        if ($device === null || $device->isExpired()) {
            return null;
        }

        return $device;
    }

    /**
     * 作用：刷新一台受信任设备的「最近使用」信息（IP 与 User-Agent）。
     *
     * @param  AdminTrustedDevice  $device  要更新的设备记录
     * @param  Request  $request  当前请求，用于取最新 IP 与 UA
     * @return void
     *
     * 为什么用 forceFill：这些字段可能不在模型 $fillable 白名单内，forceFill 可绕过
     * 批量赋值保护直接写入；此处数据源可信（服务端自取），无越权风险。
     */
    public function touch(AdminTrustedDevice $device, Request $request): void
    {
        $device->forceFill([
            'last_ip' => $request->ip(),
            'last_user_agent' => $this->userAgentSummary($request->userAgent()),
        ])->save();
    }

    /**
     * 撤销本人的一台受信任设备，返回是否命中。
     *
     * 作用：删除当前管理员名下指定 id 的受信任设备记录。
     *
     * @param  AdminUser  $admin  当前管理员
     * @param  int  $deviceId  要撤销的设备主键
     * @return bool 是否确有记录被删除（命中即 true）
     *
     * 为什么同时限定 admin_user_id 与主键：防止越权删除他人设备——即使传入他人设备 id，
     * 由于 admin_user_id 不匹配也删不到，delete() 返回 0。
     */
    public function revoke(AdminUser $admin, int $deviceId): bool
    {
        return AdminTrustedDevice::query()
            ->where('admin_user_id', $admin->id)
            ->whereKey($deviceId)
            ->delete() > 0;
    }

    /**
     * 作用：撤销当前管理员名下的全部受信任设备（如「退出所有设备」）。
     *
     * @param  AdminUser  $admin  当前管理员
     * @return int 被删除的设备数量
     */
    public function revokeAll(AdminUser $admin): int
    {
        return AdminTrustedDevice::query()
            ->where('admin_user_id', $admin->id)
            ->delete();
    }

    /**
     * 列出本人未过期的受信任设备（不含 token）。
     *
     * 作用：返回当前管理员仍在有效期内的受信任设备列表，供个人安全设置页展示。
     *
     * @param  AdminUser  $admin  当前管理员
     * @return array 设备信息数组（含 label/IP/UA/过期时间等，不含任何 token）
     *
     * 为什么不含 token：明文 token 只存在于客户端 cookie，服务端本就无法列出；
     * 展示所需仅为可辨识信息，避免泄露任何凭据。
     */
    public function list(AdminUser $admin): array
    {
        return AdminTrustedDevice::query()
            ->where('admin_user_id', $admin->id)
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->get()
            ->map(fn (AdminTrustedDevice $device): array => [
                'id' => $device->id,
                'label' => $device->label,
                'last_ip' => $device->last_ip,
                'last_user_agent' => $device->last_user_agent,
                'expires_at' => optional($device->expires_at)->toDateTimeString(),
                'created_at' => optional($device->created_at)->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * 作用：计算 token 的 sha256 摘要，用于落库与校验时的统一哈希。
     *
     * @param  string  $token  明文 token
     * @return string 十六进制 sha256 摘要
     *
     * 为什么集中一个私有方法：签发与校验必须用完全相同的算法，收敛到一处避免两侧不一致。
     */
    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * 作用：从 User-Agent 粗略识别出「浏览器 · 平台」，生成人类可读的设备标签。
     *
     * @param  string|null  $userAgent  原始 User-Agent
     * @return string 形如 "Chrome · macOS" 的设备标签，识别不出时用 Browser / Unknown 兜底
     *
     * 为什么用 match(true) 且把 Edge 放在 Chrome 之前：Edge 的 UA 同时含 "Edg" 与 "Chrome"，
     * 必须先判 Edge 才不会被误识别为 Chrome；平台/浏览器判断均取首个匹配。
     */
    private function deviceLabel(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        // 依据 UA 关键字推断操作系统平台
        $platform = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Unknown',
        };

        // 依据 UA 关键字推断浏览器；Edge 必须先于 Chrome 判断（其 UA 同时含 Chrome）
        $browser = match (true) {
            str_contains($ua, 'Edg') => 'Edge',
            str_contains($ua, 'Chrome') => 'Chrome',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Safari') => 'Safari',
            default => 'Browser',
        };

        return "{$browser} · {$platform}";
    }

    /**
     * 作用：把原始 User-Agent 脱敏并规范化为可安全入库/展示的摘要串。
     *
     * @param  string|null  $userAgent  原始 User-Agent（可能为 null）
     * @return string 脱敏、去换行并截断后的 UA 摘要
     *
     * 为什么要脱敏：UA 中可能夹带 token/password/cookie 等敏感键值，直接入库会泄露；
     * 故先过滤敏感键值、再压平换行防注入、最后限长以防超长串。
     */
    private function userAgentSummary(?string $userAgent): string
    {
        $summary = (string) $userAgent;
        // 过滤 UA 里可能夹带的敏感键值，替换为占位符
        $summary = preg_replace('/(token|password|authorization|cookie)=([^;\s]+)/i', '$1=[FILTERED]', $summary) ?? $summary;
        // 压平回车/换行/制表符为空格，防止日志换行注入并保证单行存储
        $summary = preg_replace('/[\r\n\t]+/', ' ', $summary) ?? $summary;

        // 限长 180 字符
        return Str::limit($summary, 180, '');
    }
}
