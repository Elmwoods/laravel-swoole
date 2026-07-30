<?php

namespace Tests\Unit\Ops;

use App\Services\Ops\AdvancedSystemMonitorService;
use Tests\TestCase;

class AdvancedSystemMonitorServiceTest extends TestCase
{
    public function test_disk_io_returns_safe_unavailable_result_when_iostat_is_missing(): void
    {
        $service = new class extends AdvancedSystemMonitorService
        {
            protected function hasExecutable(string $command): bool
            {
                return false;
            }
        };

        $result = $service->diskIO();

        $this->assertFalse($result['available']);
        $this->assertSame('', $result['raw']);
        $this->assertSame('iostat command unavailable', $result['message']);
        $this->assertSame('availability-check', $result['source']);
    }
}
