<?php

namespace Tests\Unit\Ops;

use App\Services\Ops\OpsReleaseCheckService;
use Tests\TestCase;

class OpsReleaseCheckServiceTest extends TestCase
{
    public function test_release_check_status_aggregation_prioritizes_fail_then_warn(): void
    {
        $service = app(OpsReleaseCheckService::class);

        $this->assertSame('pass', $service->aggregateStatus([
            ['status' => 'pass'],
            ['status' => 'pass'],
        ]));

        $this->assertSame('warn', $service->aggregateStatus([
            ['status' => 'pass'],
            ['status' => 'warn'],
        ]));

        $this->assertSame('fail', $service->aggregateStatus([
            ['status' => 'warn'],
            ['status' => 'fail'],
        ]));
    }

    public function test_release_check_summary_counts_statuses(): void
    {
        $service = app(OpsReleaseCheckService::class);

        $this->assertSame([
            'pass' => 2,
            'warn' => 1,
            'fail' => 1,
        ], $service->summarize([
            ['status' => 'pass'],
            ['status' => 'warn'],
            ['status' => 'pass'],
            ['status' => 'fail'],
        ]));
    }

    public function test_release_check_sanitizes_nested_sensitive_payload(): void
    {
        $service = app(OpsReleaseCheckService::class);

        $sanitized = $service->sanitize([
            'message' => str_repeat('A', 800),
            'password' => 'plain',
            'nested' => [
                'token' => 'unsafe-token',
                'cookie' => 'unsafe-cookie',
                'private_key' => 'unsafe-key',
                'safe' => 'visible',
            ],
        ]);

        $this->assertSame('[FILTERED]', $sanitized['password']);
        $this->assertSame('[FILTERED]', $sanitized['nested']['token']);
        $this->assertSame('[FILTERED]', $sanitized['nested']['cookie']);
        $this->assertSame('[FILTERED]', $sanitized['nested']['private_key']);
        $this->assertSame('visible', $sanitized['nested']['safe']);
        $this->assertLessThanOrEqual(503, mb_strlen($sanitized['message']));
        $this->assertStringEndsWith('...', $sanitized['message']);
    }
}
