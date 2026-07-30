<?php

namespace App\Services\Admin;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;

class AdminSessionSecurityService
{
    public const LAST_ACTIVITY_SESSION_KEY = 'admin_last_activity_at';

    public const IDLE_TIMEOUT_MINUTES = 120;

    public const IDLE_TIMEOUT_SECONDS = self::IDLE_TIMEOUT_MINUTES * 60;

    public function touch(Request $request, ?CarbonInterface $now = null): void
    {
        $request->session()->put(self::LAST_ACTIVITY_SESSION_KEY, ($now ?? now())->timestamp);
    }

    public function lastActivityAt(Request $request): ?int
    {
        $value = $request->session()->get(self::LAST_ACTIVITY_SESSION_KEY);

        return is_numeric($value) ? (int) $value : null;
    }

    public function isIdleTimedOut(?int $lastActivityAt, ?CarbonInterface $now = null): bool
    {
        if ($lastActivityAt === null) {
            return false;
        }

        return (($now ?? now())->timestamp - $lastActivityAt) > self::IDLE_TIMEOUT_SECONDS;
    }
}
