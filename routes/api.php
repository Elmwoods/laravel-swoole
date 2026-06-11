<?php

use App\Http\Controllers\Admin\Ops\AdvancedSystemController;
use App\Http\Controllers\Admin\Ops\DockerController;
use App\Http\Controllers\Admin\Ops\Log\LogController;
use App\Http\Controllers\Admin\Ops\NetworkController;
use App\Http\Controllers\Admin\Ops\QueueController;
use App\Http\Controllers\Admin\Ops\RedisMetricsController;
use App\Http\Controllers\Admin\Ops\RedisMonitorController;
use App\Http\Controllers\Admin\Ops\SupervisorController;
use App\Http\Controllers\Admin\Ops\System\DiskController;
use App\Http\Controllers\Admin\Ops\System\DiskPushController;
use App\Http\Controllers\Admin\Ops\SystemMonitorController;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Admin\Ops\DashboardController;
use App\Http\Controllers\Admin\Ops\OctaneController;
use App\Http\Controllers\Admin\Ops\OctaneMonitorController;

/*
|--------------------------------------------------------------------------
| Ops Center
|--------------------------------------------------------------------------
*/

Route::prefix('/ops')->group(function () {

    /*
     |--------------------------------------------------------------------------
     | Dashboard
     |--------------------------------------------------------------------------
     */

    Route::get(
        '/dashboard',
        [DashboardController::class, 'index']
    );

    /*
     |--------------------------------------------------------------------------
     | Octane
     |--------------------------------------------------------------------------
     */
    Route::prefix('octane')->group(function () {
        Route::get('/status', [OctaneController::class, 'status']);
        Route::post('/reload', [OctaneController::class, 'reload']);
        Route::post('/restart', [OctaneController::class, 'restart']);
        Route::post('/stop', [OctaneController::class, 'stop']);
    });

    /*
     |--------------------------------------------------------------------------
     | Redis
     |--------------------------------------------------------------------------
     */

    Route::prefix('redis')->group(function () {
        Route::get('/info', [RedisMonitorController::class, 'info']);
        Route::get('/summary', [RedisMonitorController::class, 'summary']);
        Route::get('/hit-rate', [RedisMonitorController::class, 'hitRate']);
    });

    Route::prefix('redis-metrics')->group(function () {
        Route::get('/push', [RedisMetricsController::class, 'push']);
        Route::get('/chart', [RedisMetricsController::class, 'chart']);
    });

    /*
     |--------------------------------------------------------------------------
     | Queue
     |--------------------------------------------------------------------------
     */

    Route::prefix('queue')->group(function () {
        Route::get('/summary', [QueueController::class, 'summary']);
    });


    /*
     |--------------------------------------------------------------------------
     | Supervisor
     |--------------------------------------------------------------------------
     */
    Route::prefix('supervisor')->group(function () {

        Route::get(
            '/status',
            [SupervisorController::class, 'status']
        );

        Route::post(
            '/start/{name}',
            [SupervisorController::class, 'start']
        );

        Route::post(
            '/stop/{name}',
            [SupervisorController::class, 'stop']
        );

        Route::post(
            '/restart/{name}',
            [SupervisorController::class, 'restart']
        );

        Route::post(
            '/reread',
            [SupervisorController::class, 'reread']
        );

        Route::post(
            '/update',
            [SupervisorController::class, 'update']
        );

        Route::get(
            '/tail/{name}',
            [SupervisorController::class, 'tail']
        );

        Route::get(
            '/logs/{name}',
            [SupervisorController::class, 'logs']
        );
    });

    /*
     |--------------------------------------------------------------------------
     | Docker
     |--------------------------------------------------------------------------
     */
    Route::prefix('docker')->group(function () {

        Route::get('/containers', [DockerController::class, 'containers']);

        Route::get('/stats/{id}', [DockerController::class, 'stats']);

        Route::get('/logs/{id}', [DockerController::class, 'logs']);

        Route::post('/restart/{id}', [DockerController::class, 'restart']);

        Route::post('/start/{id}', [DockerController::class, 'start']);

        Route::post('/stop/{id}', [DockerController::class, 'stop']);
    });

    /*
    |--------------------------------------------------------------------------
    | System Monitor
    |--------------------------------------------------------------------------
    */

    Route::prefix('system')->group(function () {

        Route::get(
            '/summary',
            [SystemMonitorController::class, 'summary']
        );

        Route::get(
            '/advanced',
            [AdvancedSystemController::class, 'summary']
        );

        Route::get('/disk',
            [DiskController::class, 'index']
        );

        Route::get('/disk/push',
            [DiskPushController::class, 'push']
        );

    });

    Route::get('/network', [NetworkController::class, 'index']);


    Route::prefix('logs')
        ->group(function () {

            Route::get(
                '/laravel',
                [LogController::class, 'laravel']
            );

            Route::get(
                '/octane',
                [LogController::class, 'octane']
            );

            Route::get(
                '/redis',
                [LogController::class, 'redis']
            );

            Route::get(
                '/docker',
                [LogController::class, 'docker']
            );

        });
});

use App\Events\Ops\TestEvent;

Route::get('/ops/test-broadcast', function () {

    broadcast(
        new TestEvent([
            'hello' => 'world',
            'time' => now()->toDateTimeString(),
        ])
    );

    return [
        'success' => true,
    ];
});
