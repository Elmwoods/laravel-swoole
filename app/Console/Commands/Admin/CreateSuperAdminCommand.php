<?php

namespace App\Console\Commands\Admin;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Services\Admin\AdminPermissionRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * 创建或更新 Ops Center 首个超级管理员账号（bootstrap 引导命令）。
 *
 * 用途：在系统初始化或超级管理员全部失联时，从服务器 CLI 直接落地一个可登录的超级管理员，
 * 无需依赖任何已有后台账号。
 *
 * $signature 选项（缺省时会交互式询问，密码用 secret() 隐藏输入）：
 *  - --name     后台管理员姓名
 *  - --email    后台管理员邮箱（作为 updateOrCreate 的唯一键，已存在则更新）
 *  - --password 后台管理员密码（写库前经 Hash::make 加密）
 *
 * 幂等：以 email 为键 updateOrCreate，重复执行只会更新姓名 / 密码 / 启用状态并补挂 super_admin 角色，
 * 不会产生重复账号，因此可安全反复运行。
 * 非定时命令，通常由运维手动执行。
 */
class CreateSuperAdminCommand extends Command
{
    protected $signature = 'admin:create-super
        {--name= : 后台管理员姓名}
        {--email= : 后台管理员邮箱}
        {--password= : 后台管理员密码}';

    protected $description = 'Create or update the first Ops Center super admin';

    public function handle(AdminPermissionRegistry $registry): int
    {
        $registry->syncDefaults();

        $name = (string) ($this->option('name') ?: $this->ask('Name'));
        $email = (string) ($this->option('email') ?: $this->ask('Email'));
        $password = (string) ($this->option('password') ?: $this->secret('Password'));

        try {
            validator([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ], [
                'name' => ['required', 'string', 'max:80'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:8', 'max:255'],
            ])->validate();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $admin = AdminUser::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'is_active' => true,
            ],
        );

        $role = AdminRole::query()->where('slug', 'super_admin')->firstOrFail();
        $admin->roles()->syncWithoutDetaching([$role->id]);

        $this->info("Super admin ready: {$admin->email}");

        return self::SUCCESS;
    }
}
