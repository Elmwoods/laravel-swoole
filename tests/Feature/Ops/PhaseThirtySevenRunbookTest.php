<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Services\Ops\AlertCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseThirtySevenRunbookTest extends TestCase
{
    use RefreshDatabase;

    private function alert(string $source): OpsAlert
    {
        return OpsAlert::query()->create([
            'fingerprint' => sha1($source.uniqid()),
            'source' => $source, 'severity' => 'warning', 'title' => 't', 'message' => 'm',
            'status' => 'open', 'hit_count' => 1, 'last_seen_at' => now(),
        ]);
    }

    public function test_serialize_includes_runbook_for_configured_source(): void
    {
        config()->set('ops.alerts.runbooks', [
            'disk' => ['url' => 'https://rb.example.com/disk', 'steps' => ['清理日志', '扩容磁盘', '']],
        ]);

        $data = app(AlertCenterService::class)->serialize($this->alert('disk'));

        $this->assertSame('https://rb.example.com/disk', $data['runbook']['url']);
        $this->assertSame(['清理日志', '扩容磁盘'], $data['runbook']['steps']); // 空步骤被过滤
    }

    public function test_unconfigured_source_has_null_runbook(): void
    {
        config()->set('ops.alerts.runbooks', ['disk' => ['url' => 'x', 'steps' => []]]);

        $data = app(AlertCenterService::class)->serialize($this->alert('queue'));

        $this->assertNull($data['runbook']);
    }

    public function test_list_endpoint_exposes_runbook(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        config()->set('ops.alerts.runbooks', ['disk' => ['url' => 'https://rb/disk', 'steps' => ['a']]]);
        $this->alert('disk');

        $items = $this->getJson('/api/ops/alerts')->assertOk()->json('data.items');
        $this->assertSame('https://rb/disk', $items[0]['runbook']['url']);
    }
}
