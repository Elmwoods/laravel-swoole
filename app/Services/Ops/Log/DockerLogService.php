<?php

namespace App\Services\Ops\Log;

use App\DTO\Ops\Log\LogQueryDTO;
use Symfony\Component\Process\Process;

class DockerLogService
{
    public function __construct(private readonly LogFileReaderService $reader) {}

    public function latest(
        string $container,
        int|LogQueryDTO $query = 200
    ): array {
        $dto = $query instanceof LogQueryDTO ? $query : new LogQueryDTO(lines: $query);

        if (! preg_match('/^[A-Za-z0-9_.:-]+$/', $container)) {
            return $this->reader->emptyResult('docker', 'Docker 容器标识不合法');
        }

        $process = new Process([
            'docker',
            'logs',
            '--tail=' . max(10, min($dto->lines, 1000)),
            $container,
        ]);

        $process->setTimeout(10);
        $process->run();

        $output = $process->getOutput() ?: $process->getErrorOutput();
        $lines = trim($output) === ''
            ? []
            : preg_split('/\r\n|\r|\n/', trim($output));

        if (! is_array($lines)) {
            $lines = [];
        }

        $result = $this->reader->fromLines($lines, $dto, 'docker:'.$container);

        if (! $process->isSuccessful()) {
            $result['available'] = false;
            $result['message'] = 'Docker 日志读取失败';
        }

        return $result;
    }
}
