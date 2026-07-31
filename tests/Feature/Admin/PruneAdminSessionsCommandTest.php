<?php

namespace Tests\Feature\Admin;

use App\Models\AdminSession;
use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * admin:sessions:prune 命令测试。
 *
 * 该命令用于清理过期（长时间无活动）的后台管理会话（AdminSession）。
 * 覆盖场景：仅删除过期会话而保留活跃会话、--dry-run 只统计不删除、
 * 以及非法 --days 参数（过小或非数字）时命令失败退出。
 */
class PruneAdminSessionsCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 验证命令只清理陈旧会话：
     * last_activity_at 在保留期内的会话（1 天前）保留，超出保留期的（40 天前）被删除。
     */
    public function test_it_prunes_only_stale_sessions(): void
    {
        $admin = $this->makeAdmin();
        // fresh：最后活跃于 1 天前，位于默认保留窗口内，应被保留
        $fresh = $this->makeSession($admin, 'fresh', now()->subDay());
        // stale：最后活跃于 40 天前，超出默认保留期，应被清理
        $stale = $this->makeSession($admin, 'stale', now()->subDays(40));

        $this->artisan('admin:sessions:prune')->assertExitCode(0);

        // 活跃会话仍存在
        $this->assertModelExists($fresh->fresh());
        // 过期会话已被删除
        $this->assertNull(AdminSession::query()->find($stale->id));
    }

    /**
     * 验证 --dry-run 模式只做预演统计而不实际删除：
     * 输出应包含"将删除 1 条"的提示，但数据库中过期会话仍保留（count 仍为 1）。
     */
    public function test_dry_run_deletes_nothing(): void
    {
        $admin = $this->makeAdmin();
        // 仅造 1 条过期会话，用于验证预演统计数量与"未删除"结果
        $this->makeSession($admin, 'stale', now()->subDays(40));

        // --dry-run 开启预演模式：只计算并打印将要删除的数量，不执行删除
        $this->artisan('admin:sessions:prune', ['--dry-run' => true])
            ->expectsOutputToContain('将删除 1 条')
            ->assertExitCode(0);

        // 预演不落库，会话总数保持为 1
        $this->assertSame(1, AdminSession::query()->count());
    }

    /**
     * 验证非法 --days 参数会导致命令失败（退出码 1）：
     * 过小的天数（3，低于允许下限）与非数字值（abc）都应被参数校验拒绝。
     */
    public function test_invalid_days_fails(): void
    {
        // days=3 低于允许的最小保留天数，视为非法
        $this->artisan('admin:sessions:prune', ['--days' => 3])->assertExitCode(1);
        // days=abc 非数字，无法解析为天数，视为非法
        $this->artisan('admin:sessions:prune', ['--days' => 'abc'])->assertExitCode(1);
    }

    /**
     * 测试辅助方法：创建一个启用状态的管理员用户，邮箱使用唯一伪造值避免冲突。
     */
    private function makeAdmin(): AdminUser
    {
        return AdminUser::query()->create([
            'name' => 'A',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ]);
    }

    /**
     * 测试辅助方法：为指定管理员创建一条会话记录。
     * $seed 用于生成不同的会话 token 哈希（保证唯一），
     * $lastActivity 指定最后活跃时间，用来控制该会话是否被判定为过期。
     */
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
