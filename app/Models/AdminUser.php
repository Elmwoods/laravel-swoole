<?php

namespace App\Models;

use App\Services\Admin\AdminPermissionRegistry;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * 后台管理员用户模型。
 *
 * Ops Center 的登录主体，承载 RBAC 鉴权（经角色→权限）与账户安全能力
 * （密码、双因子验证 2FA、会话版本号强制下线等）。
 * 通过 #[Hidden] 属性确保 password / remember_token 不会被序列化输出。
 */
#[Hidden(['password', 'remember_token'])]
class AdminUser extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',                       // 姓名/昵称
        'email',                      // 登录邮箱（唯一）
        'password',                   // 密码（写入时自动哈希，见 casts）
        'password_changed_at',        // 最近改密时间（用于强制定期改密策略）
        'session_version',            // 会话版本号：递增即可使所有旧会话失效（强制下线）
        'is_active',                  // 账户是否启用（停用后无法登录/鉴权）
        'last_login_at',              // 最近登录时间
        'last_login_ip',              // 最近登录 IP
        'last_login_user_agent',      // 最近登录 UA
        'two_factor_secret',          // 2FA 密钥（加密存储，见 casts）
        'two_factor_confirmed_at',    // 2FA 确认启用时间（为空表示尚未完成绑定）
        'two_factor_recovery_codes',  // 2FA 恢复码数组（加密/JSON）
        'two_factor_last_used_step',  // 上次已消费的 TOTP 时间步（防止同一验证码重放）
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',                   // 启用开关转布尔
            'password_changed_at' => 'datetime',        // 改密时间转 Carbon
            'session_version' => 'integer',             // 会话版本为整数，便于自增比较
            'last_login_at' => 'datetime',
            'password' => 'hashed',                     // 赋值即自动哈希，杜绝明文密码入库
            'two_factor_secret' => 'encrypted',         // 2FA 密钥加密存储，读取时自动解密
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_recovery_codes' => 'array',     // 恢复码 JSON <-> 数组
            'two_factor_last_used_step' => 'integer',   // TOTP 时间步为整数
        ];
    }

    /**
     * 该用户拥有的全部角色（多对多）。
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(AdminRole::class);
    }

    /**
     * 该用户的启用中角色（多对多，仅 is_active=true）——鉴权只认活跃角色。
     */
    public function activeRoles(): BelongsToMany
    {
        return $this->roles()->where('is_active', true);
    }

    /**
     * 判断用户是否拥有某权限：停用直接否；超管全通过；否则查活跃角色的权限。
     */
    public function hasPermission(string $slug): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->activeRoles()->where('slug', 'super_admin')->exists()) {
            return true;
        }

        return $this->activeRoles()
            ->whereHas('permissions', fn ($query) => $query->where('slug', $slug))
            ->exists();
    }

    /**
     * 返回该用户所有权限 slug：停用返回空；超管返回全量注册权限；否则取活跃角色权限去重。
     */
    public function permissionSlugs(): array
    {
        if (! $this->is_active) {
            return [];
        }

        if ($this->activeRoles()->where('slug', 'super_admin')->exists()) {
            return AdminPermissionRegistry::slugs();
        }

        return $this->activeRoles()
            ->with('permissions')
            ->get()
            ->flatMap(fn (AdminRole $role) => $role->permissions->pluck('slug'))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * 是否为超级管理员（启用且拥有活跃 super_admin 角色）。
     */
    public function isSuperAdmin(): bool
    {
        return $this->is_active && $this->activeRoles()->where('slug', 'super_admin')->exists();
    }

    /**
     * 校验会话版本号是否与当前一致——不一致说明会话已被强制失效（需重新登录）。
     */
    public function sessionVersionMatches(?int $sessionVersion): bool
    {
        return $sessionVersion !== null && $sessionVersion === (int) $this->session_version;
    }

    /**
     * 是否已启用双因子验证（密钥非空且已确认绑定）。
     */
    public function twoFactorEnabled(): bool
    {
        return is_string($this->two_factor_secret)
            && $this->two_factor_secret !== ''
            && $this->two_factor_confirmed_at !== null;
    }
}
