<?php

namespace App\Services\Ops\Log;

use App\DTO\Ops\Log\LogQueryDTO;
use Symfony\Component\Process\Process;

/**
 * Docker 容器日志服务。
 *
 * 运维中心「日志」板块的一个来源：通过执行 `docker logs <容器>` 抓取容器标准
 * 输出，再交给 LogFileReaderService 统一解析成日志事件。这里用 Symfony Process
 * 以数组形式传参（而非拼字符串命令），配合容器名白名单正则，避免命令注入。
 */
class DockerLogService
{
    /**
     * 作用：注入统一的日志文件读取服务（复用其行解析/分页能力）。
     *
     * @param  LogFileReaderService  $reader  负责把日志行聚合成事件并分页的底层读取器
     */
    public function __construct(private readonly LogFileReaderService $reader) {}

    /**
     * 读取指定 Docker 容器的最新日志。
     *
     * 作用：校验容器名 → 组装 docker logs 命令 → 运行并解析输出 → 交给 reader 统一成结果。
     *
     * @param  string  $container  容器名或 ID
     * @param  int|LogQueryDTO  $query  查询条件；传 int 时按行数快捷构造 DTO
     * @return array 统一的日志查询结果；容器名非法或命令失败时带 available=false 提示
     */
    public function latest(
        string $container,
        int|LogQueryDTO $query = 200
    ): array {
        // 兼容旧调用：直接传行数（int）时包装成 DTO；否则用传入的完整查询条件。
        $dto = $query instanceof LogQueryDTO ? $query : new LogQueryDTO(lines: $query);

        // 容器标识白名单：只允许字母数字及 _ . : - ，挡住命令注入与非法字符（即便用 Process 数组传参也先行拒绝）。
        if (! preg_match('/^[A-Za-z0-9_.:-]+$/', $container)) {
            return $this->reader->emptyResult('docker', 'Docker 容器标识不合法');
        }

        // 以数组形式构造命令，Process 会逐个转义参数，不经过 shell 拼接。
        $command = [
            'docker',
            'logs',
        ];

        // 非 full 模式只取尾部 N 行，行数夹到 [10,1000]，避免拉取整个容器历史日志。
        if ($dto->mode !== 'full') {
            $command[] = '--tail='.max(10, min($dto->lines, 1000));
        }

        $command[] = $container;

        $process = new Process($command);

        // full 模式日志量更大，给更长超时（20s），tail 模式 10s 足够。
        $process->setTimeout($dto->mode === 'full' ? 20 : 10);
        $process->run();

        // docker logs 会把容器 stderr 也算作日志：优先取 stdout，为空时退回 stderr（很多镜像日志走 stderr）。
        $output = $process->getOutput() ?: $process->getErrorOutput();
        // 按任意换行风格切分成行；空输出直接给空数组，避免 explode 产生一个空串元素。
        $lines = trim($output) === ''
            ? []
            : preg_split('/\r\n|\r|\n/', trim($output));

        // preg_split 理论上可能返回 false，兜底成空数组。
        if (! is_array($lines)) {
            $lines = [];
        }

        // 来源标识带容器名，前端可区分不同容器；复用 reader 做统一的事件聚合与分页。
        $result = $this->reader->fromLines($lines, $dto, 'docker:'.$container);
        $result['mode'] = $dto->mode;

        // 命令非 0 退出（容器不存在、docker 不可用等）：保留已解析内容，同时标记不可用并给出提示。
        if (! $process->isSuccessful()) {
            $result['available'] = false;
            $result['message'] = 'Docker 日志读取失败';
        }

        return $result;
    }
}
