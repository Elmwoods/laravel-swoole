<?php

namespace App\Traits;

/**
 * API 统一响应 Trait。
 *
 * 职责：为控制器提供标准化的 JSON 响应辅助方法，统一整个接口层的返回结构，
 * 返回体固定包含 code / message / data / timestamp 四个字段：
 * - success()：业务成功响应，code 固定为 0，携带业务数据。
 * - error()：业务失败响应，code 为错误码（默认 500），data 为 null。
 */
trait ApiResponse
{
    // 成功响应：code 恒为 0，data 携带业务数据，timestamp 为当前 Unix 时间戳
    protected function success(
        mixed $data = null,
        string $message = 'success'
    ) {
        return response()->json([
            'code' => 0,
            'message' => $message,
            'data' => $data,
            'timestamp' => now()->timestamp,
        ]);
    }

    // 失败响应：code 为业务/HTTP 错误码（默认 500），data 恒为 null
    protected function error(
        string $message,
        int $code = 500
    ) {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'data' => null,
            'timestamp' => now()->timestamp,
        ]);
    }
}
