<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * 前台普通用户模型（Laravel 脚手架默认用户）。
 *
 * 区别于后台的 AdminUser：此模型面向应用前台的普通账户，不参与 Ops Center 的
 * RBAC 鉴权。通过属性声明可批量赋值 name/email/password，并隐藏敏感字段。
 */
#[Fillable(['name', 'email', 'password'])] // 可批量赋值字段（PHP 属性方式声明 $fillable）
#[Hidden(['password', 'remember_token'])] // 序列化输出时隐藏的敏感字段
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',   // 邮箱验证时间转 Carbon
            'password' => 'hashed',              // 赋值即自动哈希，杜绝明文密码入库
        ];
    }
}
