<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlertRule;
use App\Services\Ops\AlertRuleRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ops Center 第 33 阶段：告警规则的导入/导出（Rule I/O）接口测试。
 *
 * 覆盖场景：管理员可将全部可调告警规则导出为可读结构，也可批量导入规则；
 * 导入时对未知 key、非法阈值（超上限、critical < warning）逐条跳过并报告原因，
 * 同时校验导出需 view 权限、导入需 manage 权限，以及请求体结构校验与审计落库。
 */
class PhaseThirtyThreeRuleIoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 验证导出接口返回所有注册规则，且每条规则仅暴露可调字段。
     * 以 view 权限访问导出端点，断言返回条数与 registry 中 allowedKeys 一致，
     * 并检查 disk_usage 规则字段顺序恰为 key/name/warning_threshold/critical_threshold/is_active。
     */
    public function test_export_returns_tunable_fields_for_all_rules(): void
    {
        // 导出属于只读操作，仅需 view 权限。
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $payload = $this->getJson('/api/ops/alerts/rules/export')->assertOk()->json('data');

        // 导出结果带有导出时间戳。
        $this->assertArrayHasKey('exported_at', $payload);
        // 导出条数应等于 registry 中允许导出的规则 key 总数。
        $this->assertCount(count(app(AlertRuleRegistryService::class)->allowedKeys()), $payload['rules']);

        // 取磁盘使用率规则，验证只暴露白名单内的可调字段（含顺序）。
        $disk = collect($payload['rules'])->firstWhere('key', 'disk_usage');
        $this->assertSame(['key', 'name', 'warning_threshold', 'critical_threshold', 'is_active'], array_keys($disk));
    }

    /**
     * 验证导入接口只应用合法规则、逐条跳过非法规则并给出原因，最终写入审计日志。
     * 构造 4 条规则：1 条合法、1 条未知 key、1 条阈值超上限、1 条 critical<warning，
     * 断言仅 1 条被应用、total=4，跳过项原因分别为 unknown_key / invalid，
     * 并验证 disk_usage 已按第一条合法规则落库、审计记录 rule_import 成功。
     */
    public function test_import_applies_valid_rules_and_reports_skips(): void
    {
        // 导入是写操作，需要 view + manage 两个权限。
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);

        $result = $this->postJson('/api/ops/alerts/rules/import', [
            'rules' => [
                // 合法规则：会被应用（同时把 is_active 设为 false 以便后面验证生效）。
                ['key' => 'disk_usage', 'warning_threshold' => 70, 'critical_threshold' => 90, 'is_active' => false],
                // 未知 key：应被跳过，原因 unknown_key。
                ['key' => 'not_a_real_rule', 'warning_threshold' => 5, 'critical_threshold' => null, 'is_active' => true],
                ['key' => 'system_cpu', 'warning_threshold' => 200, 'critical_threshold' => null, 'is_active' => true], // over max 100
                ['key' => 'disk_usage', 'warning_threshold' => 80, 'critical_threshold' => 50, 'is_active' => true], // critical < warning
            ],
        ])->assertOk()->json('data');

        // 4 条里只有第一条合法 → applied=1，total=4。
        $this->assertSame(1, $result['applied']);
        $this->assertSame(4, $result['total']);
        // 跳过项按 key 归集原因，逐一核对。
        $reasons = collect($result['skipped'])->pluck('reason', 'key');
        $this->assertSame('unknown_key', $reasons['not_a_real_rule']);
        $this->assertSame('invalid', $reasons['system_cpu']);

        // 落库结果应取第一条合法 disk_usage（70/90/未激活），第四条非法的不应覆盖。
        $disk = OpsAlertRule::query()->where('key', 'disk_usage')->firstOrFail();
        $this->assertEquals(70, $disk->warning_threshold);
        $this->assertEquals(90, $disk->critical_threshold);
        $this->assertFalse((bool) $disk->is_active);

        // 导入成功需留下审计日志。
        $this->assertDatabaseHas('admin_audit_logs', [
            'module' => 'ops.alerts',
            'action' => 'rule_import',
            'result' => 'success',
        ]);
    }

    /**
     * 验证导入端点的权限门：仅有 view 权限（缺 manage）时导入被拒 403。
     */
    public function test_import_requires_manage_permission(): void
    {
        // 只给 view 权限，故意不给 manage。
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $this->postJson('/api/ops/alerts/rules/import', [
            'rules' => [['key' => 'disk_usage', 'warning_threshold' => 70, 'critical_threshold' => 90, 'is_active' => true]],
        ])->assertStatus(403);
    }

    /**
     * 验证导出端点的权限门：无任何权限时导出被拒 403。
     */
    public function test_export_requires_view_permission(): void
    {
        // 不授予任何权限。
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/ops/alerts/rules/export')->assertStatus(403);
    }

    /**
     * 验证导入请求体结构校验：rules 不是数组时返回 422 且报 rules 字段错误。
     */
    public function test_import_validates_structure(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);

        // rules 传字符串而非数组 → 结构校验失败。
        $this->postJson('/api/ops/alerts/rules/import', ['rules' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rules']);
    }
}
