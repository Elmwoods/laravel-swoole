<?php

namespace App\Console\Commands\Ops\Docker;

use App\Events\Ops\Docker\DockerLogUpdated;
use App\Services\Ops\DockerService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ops:docker-logs')]
#[Description('Command description')]
class WatchDockerLogs extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {

        while (true) {
            $containers =
                app(DockerService::class)
                    ->containers();

            foreach ($containers as $container) {

                $id = $container['full_id'];

                $logs =
                    app(DockerService::class)
                        ->logs($id, 20);

                broadcast(
                    new DockerLogUpdated(
                        $id,
                        $logs
                    )
                );
            }

            sleep(5);
        }
    }
}
