<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AdminPasswordCryptoService
{
    public function publicKeyPayload(): array
    {
        $publicKey = $this->publicKey();

        return [
            'key_id' => $this->keyId($publicKey),
            'algorithm' => 'RSA-OAEP-SHA1',
            'public_key' => $publicKey,
        ];
    }

    public function decryptFromPayload(array $payload, string $field = 'password_encrypted'): string
    {
        $ciphertext = (string) ($payload[$field] ?? '');
        $keyId = (string) ($payload['password_key_id'] ?? '');

        if ($keyId !== $this->keyId($this->publicKey())) {
            throw ValidationException::withMessages([
                $field => ['密码加密密钥已失效，请刷新页面后重试。'],
            ]);
        }

        $decoded = base64_decode($ciphertext, true);

        if ($decoded === false || $decoded === '') {
            throw ValidationException::withMessages([
                $field => ['密码密文格式不正确。'],
            ]);
        }

        $decrypted = '';
        $ok = openssl_private_decrypt($decoded, $decrypted, $this->privateKey(), OPENSSL_PKCS1_OAEP_PADDING);

        if (! $ok || $decrypted === '') {
            throw ValidationException::withMessages([
                $field => ['密码密文无法解密，请刷新页面后重试。'],
            ]);
        }

        return $decrypted;
    }

    public function decryptPasswordFromPayload(array $payload, string $field = 'password_encrypted'): string
    {
        $password = $this->decryptFromPayload($payload, $field);
        $length = mb_strlen($password);

        if ($length < 8 || $length > 255) {
            throw ValidationException::withMessages([
                $field => ['密码长度必须在 8 到 255 位之间。'],
            ]);
        }

        return $password;
    }

    public function decryptConfirmedPasswordFromPayload(array $payload): string
    {
        $password = $this->decryptPasswordFromPayload($payload);
        $confirmation = $this->decryptPasswordFromPayload($payload, 'password_confirmation_encrypted');

        if ($password !== $confirmation) {
            throw ValidationException::withMessages([
                'password_confirmation_encrypted' => ['两次输入的密码不一致。'],
            ]);
        }

        return $password;
    }

    public function publicKey(): string
    {
        $details = openssl_pkey_get_details($this->privateKeyResource());

        if (! is_array($details) || empty($details['key'])) {
            throw new RuntimeException('Unable to derive admin password public key.');
        }

        return (string) $details['key'];
    }

    public function keyId(string $publicKey): string
    {
        return substr(hash('sha256', $publicKey), 0, 16);
    }

    private function privateKey()
    {
        $privateKey = $this->privateKeyResource();

        if ($privateKey === false) {
            throw new RuntimeException('Invalid admin password private key.');
        }

        return $privateKey;
    }

    private function privateKeyResource()
    {
        $privateKey = openssl_pkey_get_private($this->privateKeyPem());

        if ($privateKey === false) {
            throw new RuntimeException('Invalid admin password private key.');
        }

        return $privateKey;
    }

    private function privateKeyPem(): string
    {
        $configured = config('admin_security.password_crypto.private_key');

        if (is_string($configured) && $configured !== '') {
            return Str::replace('\n', "\n", $configured);
        }

        $path = (string) config('admin_security.password_crypto.storage_path');

        if (File::exists($path)) {
            return File::get($path);
        }

        $resource = openssl_pkey_new([
            'private_key_bits' => 4096,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false || ! openssl_pkey_export($resource, $pem)) {
            throw new RuntimeException('Unable to generate admin password key pair.');
        }

        File::ensureDirectoryExists(dirname($path), 0700);
        File::put($path, $pem, true);
        @chmod($path, 0600);

        return $pem;
    }
}
