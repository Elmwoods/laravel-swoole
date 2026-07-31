<?php

namespace Tests\Feature\Ops;

use App\Services\Ops\SystemMetricsCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * ops:metrics:persist 命令测试（系统指标采样落库）。
 *
 * 该命令调用 SystemMetricsCollector 采集 CPU/内存/交换分区等指标，并写入 ops_metric_samples 表。
 * 覆盖场景：正常采集写入一行并正确换算内存占用百分比、采集抛异常时容错不崩溃、
 * --demo 回填演示数据、以及生产环境下拒绝 --demo（防止污染真实数据）。
 *
 * 通过 mock SystemMetricsCollector 返回受控的采样形状，隔离真实系统读取。
 */
class PersistMetricsCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 验证命令正常采集并写入一行样本：
     * 采集器返回 total=1000/available=300 的内存数据，命令应据此算出 used_percent=70.0，
     * cpu_load=1.25，且仅写入 1 条记录。
     */
    public function test_command_persists_one_sample_row(): void
    {
        // mock 采集器返回固定的指标形状；shouldReceive('collect')->once() 断言恰好被调用一次
        $this->mock(SystemMetricsCollector::class, function ($mock): void {
            $mock->shouldReceive('collect')->once()->andReturn([
                'cpu' => 1.25,
                'load' => [1.25, 1.0, 0.5],
                // total=1000、available=300，故已用占比为 (1000-300)/1000 = 70%
                'memory' => ['total' => 1000, 'available' => 300],
                'swap' => ['total' => 0, 'free' => 0],
            ]);
        });

        $this->artisan('ops:metrics:persist')->assertSuccessful();

        // 应恰好写入一条采样
        $this->assertDatabaseCount('ops_metric_samples', 1);
        // 断言 cpu_load 原样落库、内存占用百分比换算为 70.0
        $this->assertDatabaseHas('ops_metric_samples', [
            'cpu_load' => 1.25,
            'memory_used_percent' => 70.0,
        ]);
    }

    /**
     * 验证采集抛异常时命令具备容错性：
     * 采集器抛出 RuntimeException（如 /proc 不可读）时，命令仍成功退出（不崩溃），
     * 但不写入任何样本。
     */
    public function test_command_is_fault_tolerant_when_collect_throws(): void
    {
        // 让采集器抛异常，模拟无法读取系统指标（如容器中 /proc 受限）
        $this->mock(SystemMetricsCollector::class, function ($mock): void {
            $mock->shouldReceive('collect')->once()->andThrow(new RuntimeException('/proc unreadable'));
        });

        // 命令应吞掉异常并成功退出，避免调度任务因单次采集失败而报错
        $this->artisan('ops:metrics:persist')->assertSuccessful();

        // 采集失败时不写入任何数据
        $this->assertDatabaseCount('ops_metric_samples', 0);
    }

    /**
     * 验证 --demo 选项回填演示样本：
     * --demo=2 生成 2 天的演示数据，共 12 条（按每天 6 个采样点回填），便于本地/演示环境铺数据。
     */
    public function test_demo_option_backfills_samples(): void
    {
        $this->artisan('ops:metrics:persist', ['--demo' => 2])->assertSuccessful();

        // 2 天 x 每天 6 个采样点 = 12 条
        $this->assertDatabaseCount('ops_metric_samples', 12);
    }

    /**
     * 验证生产环境拒绝 --demo：
     * 将环境探测为 production 后，--demo 应被拒绝（命令失败），
     * 防止演示假数据污染生产指标；断言无任何样本写入。
     */
    public function test_demo_option_is_rejected_in_production(): void
    {
        // 强制将应用环境识别为 production，触发对 --demo 的安全拦截
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->artisan('ops:metrics:persist', ['--demo' => 2])->assertFailed();

        // 生产环境下不应写入任何演示数据
        $this->assertDatabaseCount('ops_metric_samples', 0);
    }
}
