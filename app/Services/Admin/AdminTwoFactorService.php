<?php

namespace App\Services\Admin;

use App\Models\AdminTrustedDevice;
use App\Models\AdminUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminTwoFactorService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const RECOVERY_CODE_COUNT = 8;

    public function generateSecret(int $length = 32): string
    {
        $bytes = random_bytes((int) ceil($length * 5 / 8));

        return substr($this->base32Encode($bytes), 0, $length);
    }

    public function otpauthUri(string $email, string $secret): string
    {
        $issuer = 'Ops Center';
        $label = rawurlencode($issuer.':'.$email);

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            $label,
            $secret,
            rawurlencode($issuer),
        );
    }

    public function totpCode(string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $counter = intdiv($timestamp, 30);
        $key = $this->base32Decode($secret);
        $binaryCounter = pack('N*', 0).pack('N*', $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $truncated = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($truncated % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * 返回命中该验证码的时间步（intdiv(timestamp, 30)），未命中返回 null。
     *
     * 时间步用于重放保护：调用方记录已用步，拒绝重复使用同一步的验证码。
     */
    public function matchStep(string $secret, string $code, ?int $timestamp = null): ?int
    {
        $normalized = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{6}$/', $normalized)) {
            return null;
        }

        $timestamp ??= time();

        foreach ([-30, 0, 30] as $offset) {
            $candidate = $timestamp + $offset;

            if (hash_equals($this->totpCode($secret, $candidate), $normalized)) {
                return intdiv($candidate, 30);
            }
        }

        return null;
    }

    public function verifyTotp(string $secret, string $code, ?int $timestamp = null): bool
    {
        return $this->matchStep($secret, $code, $timestamp) !== null;
    }

    /**
     * 登录挑战专用校验：命中且未被重放（时间步严格大于上次已用步）才通过，
     * 通过后记录该步，使同一验证码在其窗口内无法二次使用。
     */
    public function verifyLoginTotp(AdminUser $admin, string $code, ?int $timestamp = null): bool
    {
        $secret = (string) $admin->two_factor_secret;

        if ($secret === '') {
            return false;
        }

        $step = $this->matchStep($secret, $code, $timestamp);

        if ($step === null) {
            return false;
        }

        $lastStep = $admin->two_factor_last_used_step;

        if ($lastStep !== null && $step <= (int) $lastStep) {
            return false;
        }

        $admin->forceFill([
            'two_factor_last_used_step' => $step,
        ])->save();

        return true;
    }

    public function generateRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn (): string => Str::upper(Str::random(5).'-'.Str::random(5)))
            ->all();
    }

    public function enable(AdminUser $admin, string $secret, array $recoveryCodes, ?int $usedStep = null): void
    {
        $admin->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $this->hashRecoveryCodes($recoveryCodes),
            'two_factor_last_used_step' => $usedStep,
        ])->save();
    }

    public function reset(AdminUser $admin): void
    {
        $admin->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_last_used_step' => null,
            'session_version' => ((int) $admin->session_version) + 1,
        ])->save();

        // Resetting 2FA revokes every trusted device so a lost/compromised
        // account cannot keep bypassing the 2FA challenge.
        AdminTrustedDevice::query()->where('admin_user_id', $admin->id)->delete();
    }

    public function consumeRecoveryCode(AdminUser $admin, string $code): bool
    {
        $normalized = $this->normalizeRecoveryCode($code);
        $hashes = (array) ($admin->two_factor_recovery_codes ?? []);

        foreach ($hashes as $index => $hash) {
            if (is_string($hash) && Hash::check($normalized, $hash)) {
                unset($hashes[$index]);
                $admin->forceFill([
                    'two_factor_recovery_codes' => array_values($hashes),
                ])->save();

                return true;
            }
        }

        return false;
    }

    public function securitySummary(AdminUser $admin): array
    {
        return [
            'two_factor_enabled' => $admin->twoFactorEnabled(),
            'two_factor_confirmed_at' => optional($admin->two_factor_confirmed_at)->toDateTimeString(),
        ];
    }

    private function hashRecoveryCodes(array $codes): array
    {
        return collect($codes)
            ->map(fn (string $code): string => Hash::make($this->normalizeRecoveryCode($code)))
            ->all();
    }

    private function normalizeRecoveryCode(string $code): string
    {
        return Str::upper(str_replace(' ', '', trim($code)));
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    private function base32Decode(string $secret): string
    {
        $secret = Str::upper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');
        $bits = '';

        foreach (str_split($secret) as $char) {
            $position = strpos(self::BASE32_ALPHABET, $char);

            if ($position === false) {
                continue;
            }

            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';

        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $decoded .= chr(bindec($byte));
            }
        }

        return $decoded;
    }
}
