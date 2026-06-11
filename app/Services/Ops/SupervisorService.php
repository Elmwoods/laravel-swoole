<?php

namespace App\Services\Ops;

use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\File;

/**
 * Supervisor 管理服务
 */
class SupervisorService
{
    /**
     * 获取所有 Supervisor 服务
     */
    public function status(): array
    {
        $output = $this->execute(['supervisorctl', 'status']);

        return [
            'services' => $this->parse($output),
        ];
    }

    /**
     * 启动服务
     */
    public function start(string $name): array
    {
        return [
            'service' => $name,
            'result' => $this->execute([
                'supervisorctl',
                'start',
                $name
            ])
        ];
    }

    /**
     * 停止服务
     */
    public function stop(string $name): array
    {
        return [
            'service' => $name,
            'result' => $this->execute([
                'supervisorctl',
                'stop',
                $name
            ])
        ];
    }

    /**
     * 重启服务
     */
    public function restart(string $name): array
    {
        return [
            'service' => $name,
            'result' => $this->execute([
                'supervisorctl',
                'restart',
                $name
            ])
        ];
    }

    /**
     * 重新读取配置
     */
    public function reread(): string
    {
        return $this->execute([
            'supervisorctl',
            'reread'
        ]);
    }

    /**
     * 更新配置
     */
    public function update(): string
    {
        return $this->execute([
            'supervisorctl',
            'update'
        ]);
    }

    /**
     * 查看日志
     */
    public function tail(string $name, int $lines = 100): string
    {
        return $this->execute([
            'supervisorctl',
            'tail',
            '-100',
            $name
        ]);
    }

    /**
     * 执行命令
     */
    private function execute(array $command): string
    {
        $process = new Process($command);

        $process->run();

        return $process->getOutput() ?: $process->getErrorOutput();
    }

    /**
     * 解析 status 输出
     */
    private function parse(string $output): array
    {
        $result = [];

        foreach (explode("\n", trim($output)) as $line) {

            if (empty($line)) {
                continue;
            }

            preg_match(
                '/^(\S+)\s+(\S+)\s+(.*)$/',
                $line,
                $matches
            );

            $result[] = [
                'name' => $matches[1] ?? '',
                'status' => $matches[2] ?? '',
                'description' => $matches[3] ?? '',
            ];
        }

        return $result;
    }

    /**
     * 获取日志
     */
    public function logs(
        string $service,
        int $lines = 100
    ): string {

        $file = config(
            "ops.supervisor.logs.{$service}"
        );

        if (!$file) {
            return 'Log file not configured';
        }

        if (!File::exists($file)) {
            return 'Log file not found';
        }

        $content = File::get($file);

        $rows = explode("\n", $content);

        return implode(
            "\n",
            array_slice($rows, -$lines)
        );
    }
}
