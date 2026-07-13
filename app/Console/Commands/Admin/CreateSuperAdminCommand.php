<?php

namespace App\Console\Commands\Admin;

use App\Models\AdminUser;
use App\Services\Admin\AdminPermissionRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

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

        $role = \App\Models\AdminRole::query()->where('slug', 'super_admin')->firstOrFail();
        $admin->roles()->syncWithoutDetaching([$role->id]);

        $this->info("Super admin ready: {$admin->email}");

        return self::SUCCESS;
    }
}
