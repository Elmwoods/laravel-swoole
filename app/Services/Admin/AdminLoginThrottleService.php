<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AdminLoginThrottleService
{
    private const MAX_ATTEMPTS = 5;
    private const DECAY_SECONDS = 900;

    public function key(string $email, string $ip): string
    {
        $normalizedEmail = Str::lower(trim($email));

        return 'admin-login:'.sha1($normalizedEmail.'|'.$ip);
    }

    public function tooManyAttempts(string $email, string $ip): bool
    {
        return RateLimiter::tooManyAttempts($this->key($email, $ip), self::MAX_ATTEMPTS);
    }

    public function hit(string $email, string $ip): int
    {
        return RateLimiter::hit($this->key($email, $ip), self::DECAY_SECONDS);
    }

    public function clear(string $email, string $ip): void
    {
        RateLimiter::clear($this->key($email, $ip));
    }

    public function availableIn(string $email, string $ip): int
    {
        return RateLimiter::availableIn($this->key($email, $ip));
    }
}
