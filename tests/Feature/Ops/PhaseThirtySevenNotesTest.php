<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsAlertNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 37 - 告警备注（notes）测试。
 *
 * 场景：管理员可在某条告警下添加/查看/删除处置备注（如"重启了 worker"），用于团队协作
 * 记录处置过程。本文件覆盖：
 *   - 添加备注写入作者与内容、可列出、并落审计日志；
 *   - 仅备注作者本人可删除（他人删除不生效）；
 *   - 备注正文必填（422 校验）；
 *   - 添加需 ops.alerts.manage 权限，而只读用户仍可列出。
 */
class PhaseThirtySevenNotesTest extends TestCase
{
    use RefreshDatabase;

    // 构造一条 open 告警作为备注的挂载对象。
    private function alert(): OpsAlert
    {
        return OpsAlert::query()->create([
            'fingerprint' => sha1('note-'.uniqid()),
            'source' => 'disk', 'severity' => 'warning', 'title' => 't', 'message' => 'm',
            'status' => 'open', 'hit_count' => 1, 'last_seen_at' => now(),
        ]);
    }

    // 验证：添加备注成功返回作者名与正文，随后列表可读到该备注，且写入 note_create 审计日志。
    // 需 view+manage 权限：view 用于读，manage 用于写。
    public function test_add_and_list_notes(): void
    {
        $admin = $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        $alert = $this->alert();

        $this->postJson("/api/ops/alerts/{$alert->id}/notes", ['body' => '重启了 worker'])
            ->assertOk()
            ->assertJsonPath('data.note.author', $admin->name)
            ->assertJsonPath('data.note.body', '重启了 worker');

        $items = $this->getJson("/api/ops/alerts/{$alert->id}/notes")->assertOk()->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame($admin->name, $items[0]['author']);

        $this->assertDatabaseHas('admin_audit_logs', ['module' => 'ops.alerts', 'action' => 'note_create', 'result' => 'success']);
    }

    // 验证：只有备注的创建者本人能删除；他人即使有 manage 权限也删不掉（软保护，返回 deleted=false）。
    // 先以 owner 身份创建备注，再切换成另一位管理员尝试删除。
    public function test_only_author_can_delete(): void
    {
        $owner = $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        $alert = $this->alert();
        // 直接建库一条归属于 owner 的备注。
        $note = OpsAlertNote::query()->create([
            'alert_id' => $alert->id, 'admin_user_id' => $owner->id, 'author' => $owner->name, 'body' => 'x',
        ]);

        // 另一个管理员删不掉。
        // actingAsAdminWithPermissions 会新建并登录另一位管理员，其 id 与 owner 不同。
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        $this->deleteJson("/api/ops/alerts/{$alert->id}/notes/{$note->id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', false);
        $this->assertModelExists($note->fresh());

        // 作者可删。
        // 重新以 owner 身份登录（带 session_version 以通过会话版本校验中间件）后删除应生效。
        $this->actingAs($owner, 'admin')->withSession(['admin_session_version' => (int) $owner->session_version]);
        $this->deleteJson("/api/ops/alerts/{$alert->id}/notes/{$note->id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);
        $this->assertNull(OpsAlertNote::query()->find($note->id));
    }

    // 验证：备注正文为空时返回 422，并在 body 字段上报校验错误。
    public function test_body_required(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        $alert = $this->alert();

        $this->postJson("/api/ops/alerts/{$alert->id}/notes", ['body' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    // 验证：添加备注需要 manage 权限——仅有 view 权限时添加被拒（403），但列出（读）仍允许。
    public function test_add_requires_manage_permission(): void
    {
        // 只授予 view，不授予 manage，以验证写操作被拦截。
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $alert = $this->alert();

        $this->postJson("/api/ops/alerts/{$alert->id}/notes", ['body' => 'x'])->assertStatus(403);
        // 读仍可。
        $this->getJson("/api/ops/alerts/{$alert->id}/notes")->assertOk();
    }
}
