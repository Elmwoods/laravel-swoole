<?php

namespace App\Services\Ops\Log;

use Symfony\Component\Process\Process;

class DockerLogService
{
    public function latest(
        string $container,
        int $lines = 200
    ): array {
        if (! preg_match('/^[A-Za-z0-9_.:-]+$/', $container)) {
            return ['Invalid container id'];
        }

        $process = new Process([
            'docker',
            'logs',
            '--tail=' . max(1, min($lines, 1000)),
            $container,
        ]);

        $process->setTimeout(10);
        $process->run();

        $output = $process->getOutput() ?: $process->getErrorOutput();

        return explode(PHP_EOL, trim($output));
    }
}
