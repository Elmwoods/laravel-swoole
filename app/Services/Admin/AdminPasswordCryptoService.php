<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * 管理端密码传输加密服务。
 *
 * 隶属后台安全 / 认证子系统，负责登录 / 改密表单中密码字段的端到端保护：
 * 服务端持有一对 RSA 密钥，前端用公钥以 RSA-OAEP 加密密码后提交，服务端用
 * 私钥解密，从而避免明文密码出现在请求体或日志中。私钥来源支持配置注入、
 * 磁盘持久化，或首次运行时自动生成并以 0600 权限落盘。key_id 用于前后端
 * 校验公钥版本一致，密钥轮换后旧密文会被拒绝。
 */
class AdminPasswordCryptoService
{
    /**
     * 作用：输出供前端加密使用的公钥载荷（公钥、算法标识与密钥指纹）。
     *
     * @return array{key_id: string, algorithm: string, public_key: string} 前端加密所需信息
     *
     * 为什么：附带 key_id 让前端在提交时回传，服务端据此判断加密用的公钥是否仍是当前公钥。
     */
    public function publicKeyPayload(): array
    {
        $publicKey = $this->publicKey();

        return [
            'key_id' => $this->keyId($publicKey),
            'algorithm' => 'RSA-OAEP-SHA1',
            'public_key' => $publicKey,
        ];
    }

    /**
     * 作用：从请求载荷中取出密文并用私钥解密出明文字符串。
     *
     * @param  array  $payload  请求载荷，含密文与 password_key_id
     * @param  string  $field  密文所在字段名，默认 password_encrypted
     * @return string 解密后的明文
     *
     * 为什么：先校验 key_id 匹配当前公钥——密钥轮换或页面过期会导致密文无法被当前私钥解开，
     * 提前拒绝可给出更友好的「请刷新页面」提示；解密失败一律不泄露底层 openssl 细节。
     */
    public function decryptFromPayload(array $payload, string $field = 'password_encrypted'): string
    {
        $ciphertext = (string) ($payload[$field] ?? '');
        $keyId = (string) ($payload['password_key_id'] ?? '');

        // 校验前端加密所用公钥指纹与当前公钥一致，防止用已失效/旧密钥的密文
        if ($keyId !== $this->keyId($this->publicKey())) {
            throw ValidationException::withMessages([
                $field => ['密码加密密钥已失效，请刷新页面后重试。'],
            ]);
        }

        // 密文以 base64 传输，严格模式解码以拒绝非法字符
        $decoded = base64_decode($ciphertext, true);

        if ($decoded === false || $decoded === '') {
            throw ValidationException::withMessages([
                $field => ['密码密文格式不正确。'],
            ]);
        }

        // 用私钥以 RSA-OAEP 填充解密（须与前端加密填充方式一致）
        $decrypted = '';
        $ok = openssl_private_decrypt($decoded, $decrypted, $this->privateKey(), OPENSSL_PKCS1_OAEP_PADDING);

        if (! $ok || $decrypted === '') {
            throw ValidationException::withMessages([
                $field => ['密码密文无法解密，请刷新页面后重试。'],
            ]);
        }

        return $decrypted;
    }

    /**
     * 作用：解密密码并额外校验长度是否落在允许区间。
     *
     * @param  array  $payload  请求载荷
     * @param  string  $field  密文字段名，默认 password_encrypted
     * @return string 通过长度校验的明文密码
     *
     * 为什么：长度校验须在解密后进行——密文长度不反映明文长度，只有拿到明文才能判断。
     */
    public function decryptPasswordFromPayload(array $payload, string $field = 'password_encrypted'): string
    {
        $password = $this->decryptFromPayload($payload, $field);
        // 以多字节长度计量，避免 UTF-8 字符被按字节误判
        $length = mb_strlen($password);

        if ($length < 8 || $length > 255) {
            throw ValidationException::withMessages([
                $field => ['密码长度必须在 8 到 255 位之间。'],
            ]);
        }

        return $password;
    }

    /**
     * 作用：解密密码与确认密码两个字段并校验二者一致。
     *
     * @param  array  $payload  请求载荷，含 password_encrypted 与 password_confirmation_encrypted
     * @return string 校验通过的明文密码
     *
     * 为什么：两份密码分别加密传输，只能各自解密后再比对明文；密文相等无意义（OAEP 每次加密随机化）。
     */
    public function decryptConfirmedPasswordFromPayload(array $payload): string
    {
        $password = $this->decryptPasswordFromPayload($payload);
        $confirmation = $this->decryptPasswordFromPayload($payload, 'password_confirmation_encrypted');

        // 比对两次输入的明文是否一致
        if ($password !== $confirmation) {
            throw ValidationException::withMessages([
                'password_confirmation_encrypted' => ['两次输入的密码不一致。'],
            ]);
        }

        return $password;
    }

    /**
     * 作用：从私钥派生出对应的 PEM 公钥字符串。
     *
     * @return string PEM 格式公钥
     *
     * 为什么：公钥无需单独存储——始终从私钥实时派生，保证与私钥严格配对。
     */
    public function publicKey(): string
    {
        $details = openssl_pkey_get_details($this->privateKeyResource());

        if (! is_array($details) || empty($details['key'])) {
            throw new RuntimeException('Unable to derive admin password public key.');
        }

        return (string) $details['key'];
    }

    /**
     * 作用：计算公钥的短指纹（key_id）。
     *
     * @param  string  $publicKey  PEM 公钥
     * @return string 公钥 sha256 的前 16 位十六进制
     *
     * 为什么：作为公钥版本标识供前后端比对；取前 16 位在唯一性与体积间折中。
     */
    public function keyId(string $publicKey): string
    {
        return substr(hash('sha256', $publicKey), 0, 16);
    }

    /**
     * 作用：获取可用于解密的私钥资源，失败即抛异常。
     *
     * @return \OpenSSLAsymmetricKey|resource 私钥资源
     *
     * 为什么：包一层做非空断言，让调用方无需重复判空。
     */
    private function privateKey()
    {
        $privateKey = $this->privateKeyResource();

        if ($privateKey === false) {
            throw new RuntimeException('Invalid admin password private key.');
        }

        return $privateKey;
    }

    /**
     * 作用：从 PEM 文本加载私钥资源对象。
     *
     * @return \OpenSSLAsymmetricKey|resource|false 加载成功的私钥资源，失败为 false
     *
     * 为什么：PEM 来源交由 privateKeyPem() 决定（配置/磁盘/自动生成），此处只负责解析。
     */
    private function privateKeyResource()
    {
        $privateKey = openssl_pkey_get_private($this->privateKeyPem());

        if ($privateKey === false) {
            throw new RuntimeException('Invalid admin password private key.');
        }

        return $privateKey;
    }

    /**
     * 作用：解析私钥 PEM 文本，按优先级从配置 / 磁盘获取，缺失时自动生成并落盘。
     *
     * @return string PEM 格式私钥
     *
     * 为什么：优先用配置注入（便于多实例共享同一密钥），其次读持久化文件，
     * 都没有则新建 4096 位 RSA 密钥对并以 0700 目录 / 0600 文件权限落盘，
     * 严格限制私钥的可读范围。
     */
    private function privateKeyPem(): string
    {
        // 优先级一：从配置直接注入私钥（\n 字面量还原为真正换行）
        $configured = config('admin_security.password_crypto.private_key');

        if (is_string($configured) && $configured !== '') {
            return Str::replace('\n', "\n", $configured);
        }

        // 优先级二：从持久化路径读取已生成的私钥
        $path = (string) config('admin_security.password_crypto.storage_path');

        if (File::exists($path)) {
            return File::get($path);
        }

        // 优先级三：首次运行自动生成 4096 位 RSA 密钥对
        $resource = openssl_pkey_new([
            'private_key_bits' => 4096,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false || ! openssl_pkey_export($resource, $pem)) {
            throw new RuntimeException('Unable to generate admin password key pair.');
        }

        // 以最小权限落盘：目录 0700、文件 0600，防止私钥被同机其他用户读取
        File::ensureDirectoryExists(dirname($path), 0700);
        File::put($path, $pem, true);
        @chmod($path, 0600);

        return $pem;
    }
}
