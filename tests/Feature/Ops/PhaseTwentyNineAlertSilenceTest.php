<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsAlertSilence;
use App\Services\Ops\AlertNotificationService;
use App\Services\Ops\AlertSilenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhaseTwentyNineAlertSilenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('ops.alerts.channels', ['webhook']);
        config()->set('ops.alerts.webhook.enabled', true);
        config()->set('ops.alerts.webhook.url', 'https://hook.example.com/x');
    }

    private function silence(array $sources = [], array $severities = [], bool $active = true, int $startOffset = -3600, int $endOffset = 3600): OpsAlertSilence
    {
        return OpsAlertSilence::query()->create([
            'label' => 'maint',
            'starts_at' => now()->addSeconds($startOffset),
            'ends_at' => now()->addSeconds($endOffset),
            'sources' => $sources,
            'severities' => $severities,
            'is_active' => $active,
        ]);
    }

    private function alert(string $source = 'disk', string $severity = 'critical'): OpsAlert
    {
        return new OpsAlert([
            'source' => $source,
            'severity' => $severity,
            'title' => 't',
            'message' => 'm',
            'status' => 'open',
            'hit_count' => 1,
            'last_seen_at' => now(),
        ]);
    }

    public function test_is_silenced_matching_rules(): void
    {
        $svc = app(AlertSilenceService::class);

        $this->silence(['disk'], ['critical']);
        $this->assertTrue($svc->isSilenced($this->alert('disk', 'critical')));
        $this->assertFalse($svc->isSilenced($this->alert('queue', 'critical'))); // 来源不匹配
        $this->assertFalse($svc->isSilenced($this->alert('disk', 'warning')));   // 严重级不匹配
    }

    public function test_empty_filters_match_all(): void
    {
        $this->silence([], []); // 全部来源 + 全部严重级
        $svc = app(AlertSilenceService::class);
        $this->assertTrue($svc->isSilenced($this->alert('anything', 'info')));
    }

    public function test_inactive_or_out_of_window_not_silenced(): void
    {
        $svc = app(AlertSilenceService::class);

        $this->silence(['disk'], [], false); // 停用
        $this->assertFalse($svc->isSilenced($this->alert('disk')));

        OpsAlertSilence::query()->delete();
        $this->silence(['disk'], [], true, -7200, -3600); // 已过期
        $this->assertFalse($svc->isSilenced($this->alert('disk')));
    }

    public function test_silenced_alert_is_not_dispatched(): void
    {
        Http::fake(['*hook.example.com*' => Http::response('', 200)]);
        $this->silence(['disk']);

        $result = app(AlertNotificationService::class)->send($this->alert('disk', 'critical'));

        $this->assertSame('silenced', $result['webhook']['reason']);
        Http::assertNothingSent();
    }

    public function test_non_matching_alert_still_dispatched(): void
    {
        Http::fake(['*hook.example.com*' => Http::response('', 200)]);
        $this->silence(['disk']);

        $result = app(AlertNotificationService::class)->send($this->alert('queue', 'critical'));

        $this->assertTrue($result['webhook']['sent']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'hook.example.com'));
    }

    public function test_test_notification_bypasses_silence(): void
    {
        Http::fake(['*hook.example.com*' => Http::response('', 200)]);
        $this->silence([], []); // 全局静默

        $result = app(AlertNotificationService::class)->sendTest(['webhook'], 'ping');

        $this->assertTrue($result['webhook']['sent']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'hook.example.com'));
    }

    public function test_crud_and_permission_gates(): void
    {
        // 无 manage：可列表、不可增删。
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $this->getJson('/api/ops/alerts/silences')->assertOk()->assertJsonPath('data.items', []);
        $this->postJson('/api/ops/alerts/silences', [
            'starts_at' => now()->toDateTimeString(),
            'ends_at' => now()->addHour()->toDateTimeString(),
        ])->assertStatus(403);

        // 有 manage：增删改。
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        $id = $this->postJson('/api/ops/alerts/silences', [
            'label' => '发布窗口',
            'starts_at' => now()->toDateTimeString(),
            'ends_at' => now()->addHours(2)->toDateTimeString(),
            'sources' => ['disk', 'queue'],
            'severities' => ['critical'],
        ])->assertOk()->json('data.silence.id');

        $this->assertDatabaseHas('ops_alert_silences', ['id' => $id, 'label' => '发布窗口', 'is_active' => true]);

        $this->patchJson("/api/ops/alerts/silences/{$id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.updated', true);
        $this->assertDatabaseHas('ops_alert_silences', ['id' => $id, 'is_active' => false]);

        $this->deleteJson("/api/ops/alerts/silences/{$id}")
            ->assertOk()->assertJsonPath('data.deleted', true);
        $this->assertDatabaseMissing('ops_alert_silences', ['id' => $id]);

        $this->assertDatabaseHas('admin_audit_logs', ['module' => 'ops.alerts', 'action' => 'silence_create']);
    }

    public function test_invalid_time_range_rejected(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);

        $this->postJson('/api/ops/alerts/silences', [
            'starts_at' => now()->addHour()->toDateTimeString(),
            'ends_at' => now()->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['ends_at']);
    }
}
