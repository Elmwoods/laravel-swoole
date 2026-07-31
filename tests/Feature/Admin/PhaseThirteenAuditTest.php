<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 13 后台审计日志（AdminAuditLog）功能测试。
 *
 * 覆盖场景：审计日志的分面聚合接口（facets）、关键词跨字段过滤、
 * CSV 导出（无 5000 行上限、以及公式注入转义防护），
 * 并验证相关接口的权限校验（需要 admin.audit.view 权限）。
 */
class PhaseThirteenAuditTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 验证 facets 接口的权限门禁：
     * 未登录时返回 401；已登录但仅持有无关权限（ops.dashboard.view）时返回 403。
     */
    public function test_facets_requires_permission(): void
    {
        // 未认证访问，应被拦截为 401 未授权
        $this->getJson('/api/admin/audit-logs/facets')->assertStatus(401);

        // 以仅拥有 ops.dashboard.view（与审计无关）的管理员登录，缺少 admin.audit.view，应返回 403
        $this->actingAsAdminWithPermissions(['ops.dashboard.view']);
        $this->getJson('/api/admin/audit-logs/facets')->assertStatus(403);
    }

    /**
     * 验证 facets 接口返回去重且已排序的可选值集合：
     * modules/actions/results 三个维度各自去重并按字典序升序排列，供前端筛选下拉使用。
     */
    public function test_facets_returns_distinct_sorted_values(): void
    {
        // 以持有 admin.audit.view 权限的管理员登录，方可访问审计接口
        $this->actingAsAdminWithPermissions(['admin.audit.view']);
        // 造 3 条日志：module 出现重复（admin.users x2）以验证去重，action/result 覆盖多值以验证排序
        $this->log(['module' => 'admin.users', 'action' => 'create', 'result' => 'success']);
        $this->log(['module' => 'admin.users', 'action' => 'update', 'result' => 'success']);
        $this->log(['module' => 'ops.release', 'action' => 'run', 'result' => 'failure']);

        $this->getJson('/api/admin/audit-logs/facets')
            ->assertOk()
            // modules 去重后按字典序：admin.users 在 ops.release 之前
            ->assertJsonPath('data.modules', ['admin.users', 'ops.release'])
            // actions 按字典序：create < run < update
            ->assertJsonPath('data.actions', ['create', 'run', 'update'])
            // results 按字典序：failure < success
            ->assertJsonPath('data.results', ['failure', 'success']);
    }

    /**
     * 验证列表接口的 keyword 关键词过滤能跨多个字段命中：
     * 既可匹配 admin_email（alice），也可匹配 module（docker），每次只返回命中的那一条。
     */
    public function test_index_keyword_filters_across_fields(): void
    {
        $this->actingAsAdminWithPermissions(['admin.audit.view']);
        // 两条日志分别在 admin_email 和 module 上带有可被关键词命中的特征值
        $this->log(['admin_email' => 'alice@example.com', 'module' => 'admin.users', 'action' => 'create']);
        $this->log(['admin_email' => 'bob@example.com', 'module' => 'ops.docker', 'action' => 'restart']);

        // 关键词 alice 命中 admin_email 字段，仅返回 1 条
        $this->getJson('/api/admin/audit-logs?keyword=alice')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.admin_email', 'alice@example.com');

        // 关键词 docker 命中 module 字段（ops.docker），仅返回 1 条
        $this->getJson('/api/admin/audit-logs?keyword=docker')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.module', 'ops.docker');
    }

    /**
     * 验证 CSV 导出以流式方式输出全部匹配行，不受旧的 5000 行上限限制：
     * 故意插入 5001 条记录（超过 5000），断言导出内容至少包含表头 + 5001 数据行。
     */
    public function test_export_streams_all_matching_rows_without_5000_cap(): void
    {
        $this->actingAsAdminWithPermissions(['admin.audit.view']);

        // 批量构造 5001 条记录（刻意越过 5000 上限）；用 insert 批量写入以提升造数速度
        $rows = [];
        for ($i = 0; $i < 5001; $i++) {
            $rows[] = [
                'admin_email' => 'a@example.com',
                'module' => 'admin.users',
                'action' => 'create',
                'result' => 'success',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        AdminAuditLog::query()->insert($rows);

        // 通过流式响应读取完整导出内容
        $content = $this->get('/api/admin/audit-logs/export')
            ->assertOk()
            ->streamedContent();

        // header + 5001 data rows
        // 换行符数量至少为 5002（1 行表头 + 5001 行数据），证明未被截断到 5000
        $this->assertGreaterThanOrEqual(5002, substr_count($content, "\n"));
    }

    /**
     * 验证 CSV 导出对公式注入（CSV/Excel formula injection）的转义防护：
     * message 以 = 开头（如 =HYPERLINK(...)）时，导出应在其前添加单引号前缀，
     * 使表格软件将其当作纯文本而非可执行公式。
     */
    public function test_export_escapes_formula_injection_in_message(): void
    {
        $this->actingAsAdminWithPermissions(['admin.audit.view']);
        // 构造以 = 开头的恶意 message，模拟 Excel/Sheets 公式注入攻击载荷
        $this->log(['module' => 'admin.users', 'action' => 'create', 'message' => '=HYPERLINK("http://evil")']);

        $content = $this->get('/api/admin/audit-logs/export')
            ->assertOk()
            ->streamedContent();

        // 导出内容应含带单引号前缀的 '=HYPERLINK，证明公式被转义为文本
        $this->assertStringContainsString("'=HYPERLINK", $content);
    }

    /**
     * 测试辅助方法：创建一条审计日志，默认填充常用字段，
     * 传入的 $attributes 会覆盖默认值以满足各测试用例的特定需求。
     */
    private function log(array $attributes): AdminAuditLog
    {
        return AdminAuditLog::query()->create(array_merge([
            'admin_email' => 'admin@example.com',
            'module' => 'admin.users',
            'action' => 'create',
            'result' => 'success',
            'status_code' => 200,
        ], $attributes));
    }
}
