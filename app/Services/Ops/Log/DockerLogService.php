<?php

namespace App\Services\Ops\Log;

class DockerLogService
{
    public function latest(
        string $container,
        int $lines = 200
    ): array {

        $command = sprintf(
            'docker logs --tail=%d %s 2>&1',
            $lines,
            escapeshellarg($container)
        );

        exec($command, $output);

        return $output;
    }
}
