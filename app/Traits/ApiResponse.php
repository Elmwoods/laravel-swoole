<?php

namespace App\Traits;

trait ApiResponse
{
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
