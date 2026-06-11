<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\DB;

/**
 * MySQL 状态检查
 */
class MysqlService
{
    public function info(): array
    {
        try {
            DB::select('SELECT 1');

            return [
                'connected' => true,
                'database' => DB::getDatabaseName(),
            ];
        } catch (\Throwable $e) {
            return [
                'connected' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
