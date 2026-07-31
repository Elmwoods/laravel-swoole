<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Supervisor 管理服务。
 *
 * 通过 supervisorctl 操作容器内进程，适配 Docker + Sail + Octane。
 */
class SupervisorService
{
    /**
     * 作用：执行 `supervisorctl status` 并解析为结构化服务列表。
     *
     * 获取所有 Supervisor 服务状态。
     *
     * @return array 形如 ['services' => [...]] 的服务状态数组
     */
    public function status(): array
    {
        $output = $this->execute(['supervisorctl', 'status']);

        return [
            'services' => $this->parse($output),
        ];
    }

    /**
     * 作用：启动指定 Supervisor 服务。
     *
     * 启动服务。
     *
     * @param  string  $name  Supervisor 中的 program 名称
     * @return array 含 service（服务名）与 result（命令输出）
     */
    public function start(string $name): array
    {
        return [
            'service' => $name,
            'result' => $this->execute([
                'supervisorctl',
                'start',
                $name,
            ]),
        ];
    }

    /**
     * 作用：停止指定 Supervisor 服务。
     *
     * 停止服务。
     *
     * @param  string  $name  Supervisor 中的 program 名称
     * @return array 含 service 与 result
     */
    public function stop(string $name): array
    {
        return [
            'service' => $name,
            'result' => $this->execute([
                'supervisorctl',
                'stop',
                $name,
            ]),
        ];
    }

    /**
     * 作用：重启指定 Supervisor 服务。
     *
     * 重启服务。
     *
     * @param  string  $name  Supervisor 中的 program 名称
     * @return array 含 service 与 result
     */
    public function restart(string $name): array
    {
        return [
            'service' => $name,
            'result' => $this->execute([
                'supervisorctl',
                'restart',
                $name,
            ]),
        ];
    }

    /**
     * 作用：让 Supervisor 重新读取配置文件（reread）。
     *
     * 重新读取配置。
     *
     * @return string supervisorctl 输出
     *
     * 为什么：reread 只检测配置变化但不应用；配合 update() 才能真正加载新程序。
     */
    public function reread(): string
    {
        return $this->execute([
            'supervisorctl',
            'reread',
        ]);
    }

    /**
     * 作用：应用配置变更（update），按需启停/重启受影响的程序。
     *
     * 更新配置。
     *
     * @return string supervisorctl 输出
     */
    public function update(): string
    {
        return $this->execute([
            'supervisorctl',
            'update',
        ]);
    }

    /**
     * 作用：通过 supervisorctl 查看某服务 stdout 的尾部若干行日志。
     *
     * 查看 Supervisor stdout 尾部日志。
     *
     * @param  string  $name  Supervisor program 名称
     * @param  int  $lines  返回的尾部行数，默认 100
     * @return string 日志文本
     *
     * 为什么：行数被拼进 "-{$lines}" 作为 supervisorctl tail 的参数，供快速排障。
     */
    public function tail(string $name, int $lines = 100): string
    {
        return $this->execute([
            'supervisorctl',
            'tail',
            "-{$lines}",
            $name,
        ]);
    }

    /**
     * 作用：统一执行一条 supervisorctl 命令并返回其输出。
     *
     * 执行 supervisorctl 命令。
     *
     * @param  array  $command  命令及参数数组
     * @return string 标准输出；为空时回退到标准错误输出
     *
     * 为什么：某些 supervisorctl 提示（如服务已停止）走 stderr，用 `?:` 回退保证调用方总能拿到反馈文本。
     */
    private function execute(array $command): string
    {
        $process = new Process($command);
        // supervisorctl 通常秒级返回，10 秒超时足够
        $process->setTimeout(10);

        $process->run();

        return $process->getOutput() ?: $process->getErrorOutput();
    }

    /**
     * 作用：把 `supervisorctl status` 的多行文本解析为结构化服务数组。
     *
     * 解析 supervisorctl status 输出。
     *
     * @param  string  $output  supervisorctl status 原始输出
     * @return array 每项含 name/status/description/running
     *
     * 为什么：正则 `^(\S+)\s+(\S+)\s+(.*)$` 按「服务名 状态 描述」三段拆分每行，
     * 状态列缺失时兜底 UNKNOWN，running 仅在状态严格等于 RUNNING 时为真。
     */
    private function parse(string $output): array
    {
        $result = [];

        foreach (explode("\n", trim($output)) as $line) {

            // 跳过空行，避免产生无意义的空服务项
            if (empty($line)) {
                continue;
            }

            // 第 1 组=服务名，第 2 组=状态，第 3 组=其余描述（PID/uptime 等）
            preg_match(
                '/^(\S+)\s+(\S+)\s+(.*)$/',
                $line,
                $matches
            );

            // 正则未匹配到状态列时兜底为 UNKNOWN
            $status = $matches[2] ?? 'UNKNOWN';

            $result[] = [
                'name' => $matches[1] ?? '',
                'status' => $status,
                'description' => $matches[3] ?? '',
                'running' => $status === 'RUNNING',
            ];
        }

        return $result;
    }

    /**
     * 作用：根据配置映射找到服务日志文件，读取并返回尾部若干行。
     *
     * 从配置文件读取服务日志。
     *
     * @param  string  $service  服务标识（用于查 ops.supervisor.logs.* 配置）
     * @param  int  $lines  返回的尾部行数，默认 100
     * @return string 日志尾部文本，或未配置/文件不存在的提示语
     *
     * 为什么：日志路径通过 config 白名单映射而非直接接收路径参数，
     * 避免用户传入任意路径造成目录穿越/越权读取文件。
     */
    public function logs(
        string $service,
        int $lines = 100
    ): string {

        // 从配置白名单读取该服务对应的日志文件路径
        $file = config(
            "ops.supervisor.logs.{$service}"
        );

        // 未在配置中登记该服务
        if (! $file) {
            return 'Log file not configured';
        }

        // 已配置但磁盘上文件不存在
        if (! File::exists($file)) {
            return 'Log file not found';
        }

        $content = File::get($file);

        $rows = explode("\n", $content);

        // array_slice 取负数偏移，返回最后 $lines 行
        return implode(
            "\n",
            array_slice($rows, -$lines)
        );
    }
}
