<?php

namespace App\Services\Admin;

use App\Models\AdminTrustedDevice;
use App\Models\AdminUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 管理端两步验证（2FA / TOTP）服务。
 *
 * 隶属后台安全 / 认证子系统，集中负责基于时间的一次性口令（TOTP，RFC 6238）
 * 全生命周期：生成 Base32 密钥、拼装 otpauth:// 二维码地址、按 30 秒时间步计算并
 * 校验 6 位验证码、登录挑战时的重放保护（记录已用时间步），以及一次性恢复码
 * （recovery codes）的生成、哈希存储与消费。启用 / 重置 2FA 时同步维护
 * AdminUser 上的相关字段，并在重置时吊销全部受信设备与递增会话版本。
 */
class AdminTwoFactorService
{
    // Base32 字母表（RFC 4648）：TOTP 密钥以此编码，避免大小写与易混字符（无 0/1/8/9）
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    // 每次启用 2FA 生成的一次性恢复码数量
    private const RECOVERY_CODE_COUNT = 8;

    /**
     * 作用：生成一个新的 Base32 编码 TOTP 密钥。
     *
     * @param  int  $length  期望的密钥字符长度（Base32 字符数），默认 32
     * @return string 截断到指定长度的 Base32 密钥字符串
     *
     * 为什么：每个 Base32 字符携带 5 bit，故需 ceil(length*5/8) 个随机字节才能
     * 编码出 length 个字符；用 random_bytes 保证密码学强度的随机源。
     */
    public function generateSecret(int $length = 32): string
    {
        // 每 5 bit 编码为 1 个 Base32 字符，反推所需的随机字节数
        $bytes = random_bytes((int) ceil($length * 5 / 8));

        return substr($this->base32Encode($bytes), 0, $length);
    }

    /**
     * 作用：拼装标准 otpauth:// TOTP 地址，供前端渲染二维码导入到 Authenticator。
     *
     * @param  string  $email  管理员邮箱，用作账户标签
     * @param  string  $secret  Base32 密钥
     * @return string otpauth://totp/... 格式的地址
     *
     * 为什么：算法固定为 SHA1 / 6 位 / 30 秒周期，与 totpCode() 的计算参数保持一致，
     * 否则验证器生成的码与服务端计算的码不匹配。
     */
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

    /**
     * 作用：按 RFC 6238 计算给定时刻的 6 位 TOTP 验证码。
     *
     * @param  string  $secret  Base32 密钥
     * @param  int|null  $timestamp  计算所用的 Unix 时间戳，null 表示当前时间
     * @return string 左补零的 6 位验证码
     *
     * 为什么：实现 HOTP 的动态截断（dynamic truncation）——取 HMAC 最后一字节低 4 位
     * 作为偏移，从该偏移读 4 字节并清掉最高位（避免符号位歧义），再对 100 万取模。
     */
    public function totpCode(string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        // 时间步 = 时间戳整除 30 秒周期，作为 HOTP 计数器
        $counter = intdiv($timestamp, 30);
        $key = $this->base32Decode($secret);
        // 计数器打包为 8 字节大端整数（高 4 字节恒为 0）
        $binaryCounter = pack('N*', 0).pack('N*', $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        // 动态截断：末字节低 4 位给出读取偏移
        $offset = ord(substr($hash, -1)) & 0x0F;
        // 从偏移处取 4 字节并清除最高位，得到 31 bit 无符号数
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
        // 去除用户输入中的所有空白，得到纯数字串
        $normalized = preg_replace('/\s+/', '', $code) ?? '';

        // 非 6 位纯数字直接判定不匹配
        if (! preg_match('/^\d{6}$/', $normalized)) {
            return null;
        }

        $timestamp ??= time();

        // 容差窗口：允许前后各一个时间步（±30 秒），兼容客户端与服务端的时钟漂移
        foreach ([-30, 0, 30] as $offset) {
            $candidate = $timestamp + $offset;

            // 用 hash_equals 做常量时间比较，避免时序侧信道泄露
            if (hash_equals($this->totpCode($secret, $candidate), $normalized)) {
                return intdiv($candidate, 30);
            }
        }

        return null;
    }

    /**
     * 作用：无状态地校验一个 TOTP 验证码是否有效（不做重放保护）。
     *
     * @param  string  $secret  Base32 密钥
     * @param  string  $code  用户提交的验证码
     * @param  int|null  $timestamp  校验所用的 Unix 时间戳，null 表示当前时间
     * @return bool 命中任一容差窗口的时间步即为 true
     *
     * 为什么：用于启用 2FA 时的首次确认等无需防重放的场景；登录挑战请改用
     * verifyLoginTotp() 以获得重放保护。
     */
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

        // 重放保护：时间步必须严格大于上次已用步，否则同一验证码在其窗口内会被拒绝
        if ($lastStep !== null && $step <= (int) $lastStep) {
            return false;
        }

        // 记录本次已用时间步，作为下次校验的重放下界
        $admin->forceFill([
            'two_factor_last_used_step' => $step,
        ])->save();

        return true;
    }

    /**
     * 作用：生成一批一次性恢复码（明文），用于丢失验证器时的应急登录。
     *
     * @return array<int, string> 数量为 RECOVERY_CODE_COUNT 的明文恢复码数组
     *
     * 为什么：格式为「5 位-5 位」大写随机串，便于人工抄录；此处仅返回明文，
     * 落库前须经 hashRecoveryCodes() 哈希，明文只在生成当次向管理员展示一次。
     */
    public function generateRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn (): string => Str::upper(Str::random(5).'-'.Str::random(5)))
            ->all();
    }

    /**
     * 作用：为管理员正式启用 2FA，写入密钥、恢复码哈希与确认时间。
     *
     * @param  AdminUser  $admin  目标管理员
     * @param  string  $secret  已确认的 Base32 密钥
     * @param  array<int, string>  $recoveryCodes  明文恢复码（落库前会被哈希）
     * @param  int|null  $usedStep  确认时消耗掉的时间步，用于初始化重放下界
     * @return void
     *
     * 为什么：恢复码以哈希形式存储而非明文，泄露数据库也无法直接反推恢复码；
     * 用 forceFill 绕过批量赋值保护，因这些均为安全敏感字段。
     */
    public function enable(AdminUser $admin, string $secret, array $recoveryCodes, ?int $usedStep = null): void
    {
        $admin->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            // 恢复码只存哈希，明文不落库
            'two_factor_recovery_codes' => $this->hashRecoveryCodes($recoveryCodes),
            'two_factor_last_used_step' => $usedStep,
        ])->save();
    }

    /**
     * 作用：关闭并清空管理员的 2FA 配置，同时吊销所有受信设备。
     *
     * @param  AdminUser  $admin  目标管理员
     * @return void
     *
     * 为什么：递增 session_version 会使该管理员所有既有会话失效，强制重新登录；
     * 配合清空 2FA 字段与吊销受信设备，确保被盗账户无法再绕过 2FA 挑战。
     */
    public function reset(AdminUser $admin): void
    {
        $admin->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_last_used_step' => null,
            // 递增会话版本，令该管理员所有既有会话立即失效
            'session_version' => ((int) $admin->session_version) + 1,
        ])->save();

        // Resetting 2FA revokes every trusted device so a lost/compromised
        // account cannot keep bypassing the 2FA challenge.
        AdminTrustedDevice::query()->where('admin_user_id', $admin->id)->delete();
    }

    /**
     * 作用：校验并消费一个一次性恢复码，成功则从存储中移除该码。
     *
     * @param  AdminUser  $admin  目标管理员
     * @param  string  $code  用户提交的恢复码（会先归一化）
     * @return bool 命中并成功消费返回 true，否则 false
     *
     * 为什么：恢复码「一次性」——命中后立即从数组删除并回写，防止重复使用；
     * 用 Hash::check 对哈希做常量时间比较，逐个比对而非索引查找是因存储的是哈希。
     */
    public function consumeRecoveryCode(AdminUser $admin, string $code): bool
    {
        // 归一化用户输入，与生成时的大写/去空格规则对齐
        $normalized = $this->normalizeRecoveryCode($code);
        $hashes = (array) ($admin->two_factor_recovery_codes ?? []);

        foreach ($hashes as $index => $hash) {
            // 逐个对哈希做校验；命中即销毁该码（一次性使用）
            if (is_string($hash) && Hash::check($normalized, $hash)) {
                unset($hashes[$index]);
                $admin->forceFill([
                    // 回写剩余恢复码，array_values 重排索引避免留下空洞
                    'two_factor_recovery_codes' => array_values($hashes),
                ])->save();

                return true;
            }
        }

        return false;
    }

    /**
     * 作用：返回管理员 2FA 的安全状态摘要，供前端展示。
     *
     * @param  AdminUser  $admin  目标管理员
     * @return array{two_factor_enabled: bool, two_factor_confirmed_at: string|null} 状态摘要
     */
    public function securitySummary(AdminUser $admin): array
    {
        return [
            'two_factor_enabled' => $admin->twoFactorEnabled(),
            'two_factor_confirmed_at' => optional($admin->two_factor_confirmed_at)->toDateTimeString(),
        ];
    }

    /**
     * 作用：把一批明文恢复码逐个哈希，得到可安全落库的哈希数组。
     *
     * @param  array<int, string>  $codes  明文恢复码
     * @return array<int, string> 与输入一一对应的哈希数组
     *
     * 为什么：哈希前先归一化，保证消费时的比对规则与存储时一致。
     */
    private function hashRecoveryCodes(array $codes): array
    {
        return collect($codes)
            ->map(fn (string $code): string => Hash::make($this->normalizeRecoveryCode($code)))
            ->all();
    }

    /**
     * 作用：归一化恢复码——去首尾空白、删除内部空格并转大写。
     *
     * @param  string  $code  原始恢复码
     * @return string 归一化后的恢复码
     *
     * 为什么：生成、哈希与消费三处必须用同一归一化规则，否则同一码会因大小写/空格差异比对失败。
     */
    private function normalizeRecoveryCode(string $code): string
    {
        return Str::upper(str_replace(' ', '', trim($code)));
    }

    /**
     * 作用：将二进制字节串编码为 Base32 字符串（RFC 4648）。
     *
     * @param  string  $bytes  原始字节串
     * @return string Base32 编码结果
     *
     * 为什么：先把每字节展开成 8 位二进制拼成位串，再每 5 位一组映射到字母表；
     * 末组不足 5 位时右补零，与 base32Decode 的丢弃逻辑对称。
     */
    private function base32Encode(string $bytes): string
    {
        $bits = '';

        // 逐字节转为 8 位二进制并拼接成完整位串
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        // 每 5 位映射为一个 Base32 字符，末组不足 5 位右补零
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    /**
     * 作用：将 Base32 字符串解码回原始二进制字节串。
     *
     * @param  string  $secret  Base32 密钥（容忍非字母表字符）
     * @return string 解码后的字节串
     *
     * 为什么：先剔除字母表外的字符（如分隔符/空格），再按每字符 5 位重组位串；
     * 只有凑满 8 位才输出一个字节，末尾不足 8 位的填充位被丢弃。
     */
    private function base32Decode(string $secret): string
    {
        // 归一化：转大写并剔除 A-Z2-7 之外的所有字符
        $secret = Str::upper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');
        $bits = '';

        foreach (str_split($secret) as $char) {
            $position = strpos(self::BASE32_ALPHABET, $char);

            // 跳过无法在字母表中定位的字符
            if ($position === false) {
                continue;
            }

            // 每个 Base32 字符还原为 5 位二进制
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';

        // 每满 8 位输出一个字节，末尾不足 8 位的填充位丢弃
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $decoded .= chr(bindec($byte));
            }
        }

        return $decoded;
    }
}
