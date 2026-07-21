<?php

namespace App\Console\Commands\Admin;

use App\Models\AdminUser;
use App\Services\Admin\AdminAuditService;
use App\Services\Admin\AdminTwoFactorService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResetTwoFactorCommand extends Command
{
    protected $signature = 'admin:reset-two-factor
        {--email= : 需要重置双因子认证的管理员邮箱}';

    protected $description = 'Break-glass reset of an admin\'s two-factor authentication (clears 2FA, invalidates sessions)';

    public function handle(AdminTwoFactorService $twoFactor, AdminAuditService $audit): int
    {
        $email = (string) ($this->option('email') ?: $this->ask('Email'));

        try {
            validator(['email' => $email], [
                'email' => ['required', 'email', 'max:255'],
            ])->validate();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $admin = AdminUser::query()
            ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
            ->first();

        if (! $admin) {
            $this->error("No admin found for email: {$email}");

            return self::FAILURE;
        }

        // Break-glass: intentionally no "cannot reset self" guard — this is the
        // recovery path for a locked-out sole super admin.
        $twoFactor->reset($admin);

        $audit->record(
            Request::create('http://cli/admin/reset-two-factor', 'POST'),
            'admin.users',
            'two_factor_reset_cli',
            'success',
            200,
            targetType: 'admin_users',
            targetId: (string) $admin->id,
            payload: ['actor' => 'cli', 'email' => $admin->email],
            message: 'break_glass_two_factor_reset',
            admin: $admin,
        );

        $this->info("Two-factor reset for {$admin->email}. Existing sessions invalidated; the admin must re-enroll on next login.");

        return self::SUCCESS;
    }
}
