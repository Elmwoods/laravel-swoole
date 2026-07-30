<?php

namespace App\Services\Ops\Docker;

use RuntimeException;

class DockerClient
{
    private string $baseUri;

    private array $defaultHeaders;

    public function __construct(?string $baseUri = null)
    {
        // 优先级：传入参数 > 环境变量 DOCKER_HOST > 默认 Unix Socket
        $this->baseUri = $baseUri ?? getenv('DOCKER_HOST') ?: 'unix:///var/run/docker.sock';
        $this->defaultHeaders = [
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * 发送请求并返回 JSON 解析后的数组
     */
    public function request(string $method, string $path, array $data = []): array
    {
        $response = $this->requestRaw($method, $path, $data);

        return json_decode($response, true) ?? [];
    }

    /**
     * 发送请求并返回原始响应体（不解析 JSON）
     * 适用于 logs, attach, stats 等非 JSON 端点
     */
    public function requestRaw(string $method, string $path, array $data = []): string
    {
        $url = $this->buildUrl($path);
        $ch = curl_init();

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->buildHeaders(),
        ];

        if ($method === 'POST' || $method === 'PUT') {
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        }

        // 处理 Unix Socket 连接
        if (str_starts_with($this->baseUri, 'unix://')) {
            $socketPath = substr($this->baseUri, 7);
            $options[CURLOPT_UNIX_SOCKET_PATH] = $socketPath;
            $url = 'http://localhost'.$path; // 占位，实际不经过网络
        } else {
            // tcp:// 或 http:// 地址
            $options[CURLOPT_URL] = $url;
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("Docker API request failed: {$error}");
        }

        if ($httpCode >= 400) {
            // 尝试从响应中提取更详细的错误信息
            $errorMsg = $response ?: "HTTP {$httpCode}";
            throw new RuntimeException("Docker API returned {$httpCode}: {$errorMsg}");
        }

        return $response;
    }

    private function buildUrl(string $path): string
    {
        // 若 baseUri 是 tcp:// 格式，转换为 http://
        $uri = preg_replace('/^tcp:/', 'http:', $this->baseUri);
        $uri = rtrim($uri, '/');

        return $uri.'/v1.41'.$path;
    }

    private function buildHeaders(): array
    {
        $headers = [];
        foreach ($this->defaultHeaders as $key => $value) {
            $headers[] = "{$key}: {$value}";
        }

        return $headers;
    }

    /**
     * GET 请求，返回 JSON 数组
     */
    public function get(string $path, array $query = []): array
    {
        $queryString = $query ? '?'.http_build_query($query) : '';

        return $this->request('GET', $path.$queryString);
    }

    /**
     * GET 请求，返回原始字符串（用于 logs）
     */
    public function getRaw(string $path, array $query = []): string
    {
        $queryString = $query ? '?'.http_build_query($query) : '';

        return $this->requestRaw('GET', $path.$queryString);
    }

    public function post(string $path, array $data = []): array
    {
        return $this->request('POST', $path, $data);
    }
}
