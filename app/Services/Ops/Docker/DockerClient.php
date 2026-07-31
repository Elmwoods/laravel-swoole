<?php

namespace App\Services\Ops\Docker;

use RuntimeException;

/**
 * Docker Engine API 底层 HTTP 客户端。
 *
 * 在 Ops Center 中，本类是所有 Docker 操作的最底层通道：它通过 cURL 直接与
 * Docker Engine 的 REST API（/v1.41）通信，既支持本机 Unix Socket
 * （/var/run/docker.sock），也支持远程 tcp:// / http:// 地址。
 * DockerService 依赖本类完成容器列表、日志、统计、启停等所有请求，
 * 本类只负责“发请求 + 处理错误 + 返回结果”，不掺杂任何业务逻辑。
 */
class DockerClient
{
    // Docker Engine 的连接地址（unix:// / tcp:// / http:// 三种形态之一）
    private string $baseUri;

    // 所有请求默认携带的 HTTP 头
    private array $defaultHeaders;

    /**
     * 作用：初始化 Docker 客户端，确定连接地址与默认请求头。
     *
     * @param  string|null  $baseUri  显式指定的连接地址；为空时回退到环境变量或默认 Socket
     *
     * 为什么：Docker 连接地址在不同部署下差异很大（本机 Socket / 远程 TCP），
     * 采用「参数 > 环境变量 > 默认值」的三级回退可让本类在各种环境下开箱即用。
     */
    public function __construct(?string $baseUri = null)
    {
        // 优先级：传入参数 > 环境变量 DOCKER_HOST > 默认 Unix Socket
        $this->baseUri = $baseUri ?? getenv('DOCKER_HOST') ?: 'unix:///var/run/docker.sock';
        $this->defaultHeaders = [
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * 作用：发送请求并返回 JSON 解析后的数组。
     *
     * 发送请求并返回 JSON 解析后的数组
     *
     * @param  string  $method  HTTP 方法（GET/POST/PUT 等）
     * @param  string  $path  Docker API 路径（不含版本前缀）
     * @param  array  $data  POST/PUT 时的请求体数据
     * @return array 解析后的关联数组；解析失败时返回空数组
     *
     * 为什么：用 `?? []` 兜底，保证即使响应不是合法 JSON，调用方拿到的也始终是数组，避免上层空指针。
     */
    public function request(string $method, string $path, array $data = []): array
    {
        $response = $this->requestRaw($method, $path, $data);

        return json_decode($response, true) ?? [];
    }

    /**
     * 作用：发送请求并返回原始响应体字符串（不做 JSON 解析）。
     *
     * 发送请求并返回原始响应体（不解析 JSON）
     * 适用于 logs, attach, stats 等非 JSON 端点
     *
     * @param  string  $method  HTTP 方法
     * @param  string  $path  Docker API 路径
     * @param  array  $data  POST/PUT 请求体
     * @return string 原始响应体
     *
     * @throws RuntimeException 当 cURL 执行失败或 Docker 返回 >=400 状态码时抛出
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

        // 仅写操作携带请求体，GET 请求不设置 POSTFIELDS
        if ($method === 'POST' || $method === 'PUT') {
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        }

        // 处理 Unix Socket 连接
        // 去掉 "unix://" 前缀（7 个字符）得到真实 socket 文件路径
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
        // 在关闭句柄前先取出错误信息与 HTTP 状态码，curl_close 之后无法再读取
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // cURL 执行失败（如连不上 socket），$response 为 false
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

    /**
     * 作用：将 API 路径拼接为完整请求 URL（含 Docker API 版本前缀 v1.41）。
     *
     * @param  string  $path  Docker API 路径
     * @return string 完整 URL
     *
     * 为什么：cURL 不认识 tcp:// 协议，需先用正则把 tcp: 替换为 http:，
     * 并统一拼上固定的 API 版本前缀，锁定协议兼容性。
     */
    private function buildUrl(string $path): string
    {
        // 若 baseUri 是 tcp:// 格式，转换为 http://
        $uri = preg_replace('/^tcp:/', 'http:', $this->baseUri);
        // 去掉末尾斜杠，避免拼接后出现双斜杠
        $uri = rtrim($uri, '/');

        return $uri.'/v1.41'.$path;
    }

    /**
     * 作用：把 $defaultHeaders 关联数组转换为 cURL 需要的 "Key: Value" 字符串数组。
     *
     * @return array cURL CURLOPT_HTTPHEADER 所需的字符串列表
     */
    private function buildHeaders(): array
    {
        $headers = [];
        foreach ($this->defaultHeaders as $key => $value) {
            $headers[] = "{$key}: {$value}";
        }

        return $headers;
    }

    /**
     * 作用：发起 GET 请求并返回 JSON 数组。
     *
     * GET 请求，返回 JSON 数组
     *
     * @param  string  $path  Docker API 路径
     * @param  array  $query  查询参数（会被编码为 query string 追加到路径后）
     * @return array 解析后的 JSON 数组
     */
    public function get(string $path, array $query = []): array
    {
        // 有查询参数时才拼接 "?...."，避免出现末尾多余的问号
        $queryString = $query ? '?'.http_build_query($query) : '';

        return $this->request('GET', $path.$queryString);
    }

    /**
     * 作用：发起 GET 请求并返回原始字符串（不解析 JSON）。
     *
     * GET 请求，返回原始字符串（用于 logs）
     *
     * @param  string  $path  Docker API 路径
     * @param  array  $query  查询参数
     * @return string 原始响应体
     *
     * 为什么：logs 端点返回的是带二进制帧头的流数据而非 JSON，必须走原始通道。
     */
    public function getRaw(string $path, array $query = []): string
    {
        $queryString = $query ? '?'.http_build_query($query) : '';

        return $this->requestRaw('GET', $path.$queryString);
    }

    /**
     * 作用：发起 POST 请求并返回 JSON 数组（用于容器启停/重启等写操作）。
     *
     * @param  string  $path  Docker API 路径
     * @param  array  $data  请求体数据
     * @return array 解析后的 JSON 数组
     */
    public function post(string $path, array $data = []): array
    {
        return $this->request('POST', $path, $data);
    }
}
