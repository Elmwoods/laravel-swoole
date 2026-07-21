<?php

namespace App\Services\Admin;

use App\Models\AdminTrustedDevice;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminTrustedDeviceService
{
    public const COOKIE_NAME = 'admin_trusted_device';

    public const LIFETIME_DAYS = 30;

    /**
     * 为管理员签发一台受信任设备，返回放入 cookie 的明文 token。
     *
     * 仅存 token 的 sha256 摘要，明文只在 cookie 中，服务端不可反查。
     */
    public function issue(AdminUser $admin, Request $request): string
    {
        $token = Str::random(64);

        AdminTrustedDevice::query()->create([
            'admin_user_id' => $admin->id,
            'token_hash' => $this->hash($token),
            'label' => $this->deviceLabel($request->userAgent()),
            'last_ip' => $request->ip(),
            'last_user_agent' => $this->userAgentSummary($request->userAgent()),
            'expires_at' => now()->addDays(self::LIFETIME_DAYS),
        ]);

        return $token;
    }

    /**
     * 校验管理员的受信任设备 token，命中且未过期才返回设备。
     */
    public function findValid(AdminUser $admin, ?string $token): ?AdminTrustedDevice
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        $device = AdminTrustedDevice::query()
            ->where('admin_user_id', $admin->id)
            ->where('token_hash', $this->hash($token))
            ->first();

        if ($device === null || $device->isExpired()) {
            return null;
        }

        return $device;
    }

    public function touch(AdminTrustedDevice $device, Request $request): void
    {
        $device->forceFill([
            'last_ip' => $request->ip(),
            'last_user_agent' => $this->userAgentSummary($request->userAgent()),
        ])->save();
    }

    /**
     * 撤销本人的一台受信任设备，返回是否命中。
     */
    public function revoke(AdminUser $admin, int $deviceId): bool
    {
        return AdminTrustedDevice::query()
            ->where('admin_user_id', $admin->id)
            ->whereKey($deviceId)
            ->delete() > 0;
    }

    public function revokeAll(AdminUser $admin): int
    {
        return AdminTrustedDevice::query()
            ->where('admin_user_id', $admin->id)
            ->delete();
    }

    /**
     * 列出本人未过期的受信任设备（不含 token）。
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

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

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
            str_contains($ua, 'Edg') => 'Edge',
            str_contains($ua, 'Chrome') => 'Chrome',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Safari') => 'Safari',
            default => 'Browser',
        };

        return "{$browser} · {$platform}";
    }

    private function userAgentSummary(?string $userAgent): string
    {
        $summary = (string) $userAgent;
        $summary = preg_replace('/(token|password|authorization|cookie)=([^;\s]+)/i', '$1=[FILTERED]', $summary) ?? $summary;
        $summary = preg_replace('/[\r\n\t]+/', ' ', $summary) ?? $summary;

        return Str::limit($summary, 180, '');
    }
}
