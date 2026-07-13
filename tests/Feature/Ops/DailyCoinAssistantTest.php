<?php

namespace Tests\Feature\Ops;

use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 合规金币领取辅助接口测试。
 */
class DailyCoinAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdminWithPermissions(['ops.system.view']);
        Cache::flush();
        Carbon::setTestNow('2026-07-07 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();

        parent::tearDown();
    }

    public function test_daily_reminder_is_marked_once_per_day(): void
    {
        $this->getJson('/api/ops/coin-assistant/summary')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.reminder.due', true)
            ->assertJsonPath('data.today.status', 'pending')
            ->assertJsonPath('data.safety.private_token_automation_allowed', false);

        $this->postJson('/api/ops/coin-assistant/reminder')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.reminder.due', false)
            ->assertJsonPath('data.reminder.sent_today', true);

        $this->getJson('/api/ops/coin-assistant/summary')
            ->assertOk()
            ->assertJsonPath('data.reminder.due', false)
            ->assertJsonPath('data.reminder.sent_today', true);

        Carbon::setTestNow('2026-07-08 09:00:00');

        $this->getJson('/api/ops/coin-assistant/summary')
            ->assertOk()
            ->assertJsonPath('data.reminder.due', true)
            ->assertJsonPath('data.today.date', '2026-07-08');
    }

    public function test_manual_confirmation_records_claim_failed_or_skipped_status(): void
    {
        $this->postJson('/api/ops/coin-assistant/confirm', [
            'status' => 'claimed',
            'coins' => 100,
            'note' => '手动确认领取',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.today.status', 'claimed')
            ->assertJsonPath('data.today.coins', 100)
            ->assertJsonPath('data.today.note', '手动确认领取');

        $this->getJson('/api/ops/coin-assistant/summary')
            ->assertOk()
            ->assertJsonPath('data.today.status', 'claimed')
            ->assertJsonPath('data.history.0.status', 'claimed');

        $this->postJson('/api/ops/coin-assistant/confirm', [
            'status' => 'failed',
            'note' => '需要人工验证',
        ])
            ->assertOk()
            ->assertJsonPath('data.today.status', 'failed')
            ->assertJsonPath('data.today.coins', null);
    }

    public function test_private_token_automation_requests_are_rejected(): void
    {
        $this->postJson('/api/ops/coin-assistant/automation-request', [
            'mode' => 'private_token',
            'token' => 'unsafe-token',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', '不支持私有 token、抓包复用或绕过风控的自动化方式。');
    }
}
