<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsAlertNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseThirtySevenNotesTest extends TestCase
{
    use RefreshDatabase;

    private function alert(): OpsAlert
    {
        return OpsAlert::query()->create([
            'fingerprint' => sha1('note-'.uniqid()),
            'source' => 'disk', 'severity' => 'warning', 'title' => 't', 'message' => 'm',
            'status' => 'open', 'hit_count' => 1, 'last_seen_at' => now(),
        ]);
    }

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

    public function test_only_author_can_delete(): void
    {
        $owner = $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        $alert = $this->alert();
        $note = OpsAlertNote::query()->create([
            'alert_id' => $alert->id, 'admin_user_id' => $owner->id, 'author' => $owner->name, 'body' => 'x',
        ]);

        // 另一个管理员删不掉。
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        $this->deleteJson("/api/ops/alerts/{$alert->id}/notes/{$note->id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', false);
        $this->assertModelExists($note->fresh());

        // 作者可删。
        $this->actingAs($owner, 'admin')->withSession(['admin_session_version' => (int) $owner->session_version]);
        $this->deleteJson("/api/ops/alerts/{$alert->id}/notes/{$note->id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);
        $this->assertNull(OpsAlertNote::query()->find($note->id));
    }

    public function test_body_required(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        $alert = $this->alert();

        $this->postJson("/api/ops/alerts/{$alert->id}/notes", ['body' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    public function test_add_requires_manage_permission(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $alert = $this->alert();

        $this->postJson("/api/ops/alerts/{$alert->id}/notes", ['body' => 'x'])->assertStatus(403);
        // 读仍可。
        $this->getJson("/api/ops/alerts/{$alert->id}/notes")->assertOk();
    }
}
