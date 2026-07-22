<?php

namespace Tests\Unit\Ops;

use App\Models\OpsAlert;
use App\Services\Ops\AlertCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertDigestTest extends TestCase
{
    use RefreshDatabase;

    public function test_digest_summary_counts_within_window_and_excludes_older(): void
    {
        // 窗口内（24h）：2 critical(open), 1 warning(resolved), 1 info(acknowledged)
        $this->makeAlert('critical', 'open', 'disk', now()->subHour());
        $this->makeAlert('critical', 'open', 'disk', now()->subHours(2));
        $this->makeAlert('warning', 'resolved', 'queue', now()->subHours(3));
        $this->makeAlert('info', 'acknowledged', 'security_login', now()->subHours(5));

        // 窗口外（48h 前）：不应计入
        $this->makeAlert('critical', 'open', 'disk', now()->subHours(48));

        $summary = app(AlertCenterService::class)->digestSummary(24);

        $this->assertSame(24, $summary['window_hours']);
        $this->assertSame(4, $summary['total']);
        $this->assertSame(['critical' => 2, 'warning' => 1, 'info' => 1], $summary['by_severity']);
        $this->assertSame(['open' => 2, 'acknowledged' => 1, 'resolved' => 1], $summary['by_status']);

        $sources = collect($summary['sources'])->pluck('total', 'source')->all();
        $this->assertSame(2, $sources['disk']);
        $this->assertSame(1, $sources['queue']);
        $this->assertSame(1, $sources['security_login']);
    }

    public function test_render_digest_produces_multiline_text_summary(): void
    {
        $this->makeAlert('critical', 'open', 'disk', now()->subHour());

        $service = app(AlertCenterService::class);
        $body = $service->renderDigest($service->digestSummary(24));

        $this->assertStringContainsString('过去 24 小时告警摘要', $body);
        $this->assertStringContainsString('共 1 条', $body);
        $this->assertStringContainsString('严重 1', $body);
        $this->assertStringContainsString('disk 1', $body);
    }

    public function test_hours_window_is_clamped(): void
    {
        $summary = app(AlertCenterService::class)->digestSummary(9999);
        $this->assertSame(168, $summary['window_hours']);

        $summary = app(AlertCenterService::class)->digestSummary(0);
        $this->assertSame(1, $summary['window_hours']);
    }

    private function makeAlert(string $severity, string $status, string $source, \DateTimeInterface $createdAt): void
    {
        $alert = OpsAlert::query()->create([
            'fingerprint' => $source.'-'.uniqid(),
            'source' => $source,
            'severity' => $severity,
            'title' => "{$source} alert",
            'message' => 'body',
            'status' => $status,
            'hit_count' => 1,
            'last_seen_at' => $createdAt,
        ]);

        $alert->forceFill(['created_at' => $createdAt])->save();
    }
}
