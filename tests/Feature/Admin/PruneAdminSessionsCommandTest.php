<?php

namespace Tests\Feature\Admin;

use App\Models\AdminSession;
use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PruneAdminSessionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prunes_only_stale_sessions(): void
    {
        $admin = $this->makeAdmin();
        $fresh = $this->makeSession($admin, 'fresh', now()->subDay());
        $stale = $this->makeSession($admin, 'stale', now()->subDays(40));

        $this->artisan('admin:sessions:prune')->assertExitCode(0);

        $this->assertModelExists($fresh->fresh());
        $this->assertNull(AdminSession::query()->find($stale->id));
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $admin = $this->makeAdmin();
        $this->makeSession($admin, 'stale', now()->subDays(40));

        $this->artisan('admin:sessions:prune', ['--dry-run' => true])
            ->expectsOutputToContain('将删除 1 条')
            ->assertExitCode(0);

        $this->assertSame(1, AdminSession::query()->count());
    }

    public function test_invalid_days_fails(): void
    {
        $this->artisan('admin:sessions:prune', ['--days' => 3])->assertExitCode(1);
        $this->artisan('admin:sessions:prune', ['--days' => 'abc'])->assertExitCode(1);
    }

    private function makeAdmin(): AdminUser
    {
        return AdminUser::query()->create([
            'name' => 'A',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ]);
    }

    private function makeSession(AdminUser $admin, string $seed, \DateTimeInterface $lastActivity): AdminSession
    {
        return AdminSession::query()->create([
            'admin_user_id' => $admin->id,
            'session_token_hash' => hash('sha256', $seed),
            'label' => 'Chrome · macOS',
            'ip_address' => '10.0.0.1',
            'user_agent' => 'ua',
            'last_activity_at' => $lastActivity,
        ]);
    }
}
