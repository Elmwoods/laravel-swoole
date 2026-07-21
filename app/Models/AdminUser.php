<?php

namespace App\Models;

use App\Services\Admin\AdminPermissionRegistry;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Hidden(['password', 'remember_token'])]
class AdminUser extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'password_changed_at',
        'session_version',
        'is_active',
        'last_login_at',
        'last_login_ip',
        'last_login_user_agent',
        'two_factor_secret',
        'two_factor_confirmed_at',
        'two_factor_recovery_codes',
        'two_factor_last_used_step',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'password_changed_at' => 'datetime',
            'session_version' => 'integer',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_recovery_codes' => 'array',
            'two_factor_last_used_step' => 'integer',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(AdminRole::class);
    }

    public function activeRoles(): BelongsToMany
    {
        return $this->roles()->where('is_active', true);
    }

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

    public function isSuperAdmin(): bool
    {
        return $this->is_active && $this->activeRoles()->where('slug', 'super_admin')->exists();
    }

    public function sessionVersionMatches(?int $sessionVersion): bool
    {
        return $sessionVersion !== null && $sessionVersion === (int) $this->session_version;
    }

    public function twoFactorEnabled(): bool
    {
        return is_string($this->two_factor_secret)
            && $this->two_factor_secret !== ''
            && $this->two_factor_confirmed_at !== null;
    }
}
