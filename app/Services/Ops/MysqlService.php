<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\DB;

/**
 * MySQL 状态检查
 *
 * 作用：Ops Center 健康检查的一环，用一次最轻量的查询探测数据库连通性。
 * 为什么这样设计：不关心具体数据，只需确认能连通，因此以异常与否作为判断依据。
 */
class MysqlService
{
    /**
     * 检查数据库连接状态。
     *
     * 作用：执行 `SELECT 1` 探针查询，成功即视为已连接并返回库名，失败则捕获
     *       异常返回错误信息，保证健康检查接口本身永不抛异常。
     * 为什么用 SELECT 1：这是开销最小的连通性探针，不依赖任何表结构。
     *
     * @return array connected=true+database，或 connected=false+error
     */
    public function info(): array
    {
        try {
            DB::select('SELECT 1'); // 轻量探针：能执行即说明连接可用

            return [
                'connected' => true,
                'database' => DB::getDatabaseName(),
            ];
        } catch (\Throwable $e) {
            // 捕获一切 Throwable（连接失败/权限/超时等），转成结构化结果而非抛出
            return [
                'connected' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
