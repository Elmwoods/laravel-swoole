<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\Redis;

/**
 * Redis 历史缓存
 *
 * 作用：用一个 Redis List 充当系统指标的“环形滑动窗口”，保存最近若干帧
 *       快照，供前端绘制趋势曲线。
 * 为什么用 List + LTRIM：LPUSH 头插 + LTRIM 截断可低成本维持固定长度队列，
 *       天然实现“只保留最近 N 条”的滚动缓存，无需额外过期逻辑。
 */
class MetricsBufferService
{
    // Redis List 的键名，集中定义避免读写两端写错字符串
    const string KEY = 'ops:metrics:system';

    /**
     * 追加一帧指标快照。
     *
     * 作用：给数据打上时间戳，JSON 序列化后头插入 List，并裁剪到最多 60 条。
     * 为什么 LTRIM 到 0..59：只保留最近 60 帧，形成固定容量的滚动窗口，防止
     *       历史无限增长撑爆 Redis 内存。
     *
     * @param  array  $data  一帧系统指标（会被就地追加 time 字段）
     */
    public function push(array $data): void
    {
        $data['time'] = now()->toDateTimeString(); // 打时间戳，供前端做时间轴

        Redis::lpush(self::KEY, json_encode($data)); // 头插最新帧
        Redis::ltrim(self::KEY, 0, 59); // 只保留下标 0..59 共 60 帧
    }

    /**
     * 读取最近的历史指标。
     *
     * 作用：取出 List 头部最近 30 帧并反序列化成数组返回。
     * 为什么只取 30 条：读取端展示的窗口比写入端保留的 60 帧更短，减少传输量；
     *       因 LPUSH 头插，下标 0 即最新，0..29 就是最近 30 帧。
     *
     * @return array 反序列化后的指标数组列表（最新在前）
     */
    public function history(): array
    {
        $list = Redis::lrange(self::KEY, 0, 29); // 取最近 30 帧原始 JSON

        // 逐条 json_decode 还原为关联数组
        return array_map(fn ($i) => json_decode($i, true), $list);
    }
}
