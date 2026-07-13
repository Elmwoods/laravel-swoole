<?php

use App\Events\Ops\TestEvent;
use App\Http\Controllers\Admin\Auth\AdminAuthController;
use App\Http\Controllers\Admin\Ops\AdvancedSystemController;
use App\Http\Controllers\Admin\Ops\AlertController;
use App\Http\Controllers\Admin\Ops\DailyCoinAssistantController;
use App\Http\Controllers\Admin\Ops\DashboardController;
use App\Http\Controllers\Admin\Ops\DockerController;
use App\Http\Controllers\Admin\Ops\Log\LogController;
use App\Http\Controllers\Admin\Ops\NetworkController;
use App\Http\Controllers\Admin\Ops\OctaneController;
use App\Http\Controllers\Admin\Ops\QueueController;
use App\Http\Controllers\Admin\Ops\RedisMetricsController;
use App\Http\Controllers\Admin\Ops\RedisMonitorController;
use App\Http\Controllers\Admin\Ops\SupervisorController;
use App\Http\Controllers\Admin\Ops\System\DiskController;
use App\Http\Controllers\Admin\Ops\System\DiskPushController;
use App\Http\Controllers\Admin\Ops\SystemMonitorController;
use App\Http\Controllers\Admin\Security\AdminAuditLogController;
use App\Http\Controllers\Admin\Security\AdminRoleController;
use App\Http\Controllers\Admin\Security\AdminUserController;
use Illuminate\Support\Facades\Route;

Route::prefix('/admin')
    ->middleware('web')
    ->group(function (): void {
        Route::prefix('auth')->group(function (): void {
            Route::post('/login', [AdminAuthController::class, 'login']);

            Route::middleware('admin.auth')->group(function (): void {
                Route::get('/me', [AdminAuthController::class, 'me']);
                Route::post('/logout', [AdminAuthController::class, 'logout']);
            });
        });

        Route::middleware('admin.auth')->group(function (): void {
            Route::middleware('admin.permission:admin.users.manage')->group(function (): void {
                Route::get('/users', [AdminUserController::class, 'index']);
                Route::post('/users', [AdminUserController::class, 'store'])
                    ->middleware('admin.audit:admin.users,create');
                Route::put('/users/{adminUser}', [AdminUserController::class, 'update'])
                    ->middleware('admin.audit:admin.users,update');
                Route::post('/users/{adminUser}/reset-password', [AdminUserController::class, 'resetPassword'])
                    ->middleware('admin.audit:admin.users,reset_password');
            });

            Route::middleware('admin.permission:admin.roles.manage')->group(function (): void {
                Route::get('/roles', [AdminRoleController::class, 'index']);
                Route::post('/roles', [AdminRoleController::class, 'store'])
                    ->middleware('admin.audit:admin.roles,create');
                Route::put('/roles/{adminRole}', [AdminRoleController::class, 'update'])
                    ->middleware('admin.audit:admin.roles,update');
            });

            Route::get('/audit-logs', [AdminAuditLogController::class, 'index'])
                ->middleware('admin.permission:admin.audit.view');
        });
    });

Route::prefix('/ops')
    ->middleware(['web', 'admin.auth'])
    ->group(function (): void {
        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->middleware('admin.permission:ops.dashboard.view');

        Route::prefix('octane')
            ->middleware('admin.permission:ops.system.view')
            ->group(function (): void {
                Route::get('/status', [OctaneController::class, 'status']);
                Route::post('/reload', [OctaneController::class, 'reload'])
                    ->middleware('admin.audit:ops.octane,reload');
                Route::post('/restart', [OctaneController::class, 'restart'])
                    ->middleware('admin.audit:ops.octane,restart');
                Route::post('/stop', [OctaneController::class, 'stop'])
                    ->middleware('admin.audit:ops.octane,stop');
            });

        Route::prefix('redis')
            ->middleware('admin.permission:ops.system.view')
            ->group(function (): void {
                Route::get('/info', [RedisMonitorController::class, 'info']);
                Route::get('/summary', [RedisMonitorController::class, 'summary']);
                Route::get('/hit-rate', [RedisMonitorController::class, 'hitRate']);
            });

        Route::prefix('redis-metrics')
            ->middleware('admin.permission:ops.system.view')
            ->group(function (): void {
                Route::get('/push', [RedisMetricsController::class, 'push']);
                Route::get('/chart', [RedisMetricsController::class, 'chart']);
            });

        Route::prefix('queue')
            ->middleware('admin.permission:ops.system.view')
            ->group(function (): void {
                Route::get('/summary', [QueueController::class, 'summary']);
            });

        Route::prefix('supervisor')->group(function (): void {
            Route::get('/status', [SupervisorController::class, 'status'])
                ->middleware('admin.permission:ops.supervisor.view');
            Route::get('/tail/{name}', [SupervisorController::class, 'tail'])
                ->middleware('admin.permission:ops.supervisor.view');
            Route::get('/logs/{name}', [SupervisorController::class, 'logs'])
                ->middleware('admin.permission:ops.supervisor.view');
            Route::post('/start/{name}', [SupervisorController::class, 'start'])
                ->middleware(['admin.permission:ops.supervisor.control', 'admin.audit:ops.supervisor,start']);
            Route::post('/stop/{name}', [SupervisorController::class, 'stop'])
                ->middleware(['admin.permission:ops.supervisor.control', 'admin.audit:ops.supervisor,stop']);
            Route::post('/restart/{name}', [SupervisorController::class, 'restart'])
                ->middleware(['admin.permission:ops.supervisor.control', 'admin.audit:ops.supervisor,restart']);
            Route::post('/reread', [SupervisorController::class, 'reread'])
                ->middleware(['admin.permission:ops.supervisor.control', 'admin.audit:ops.supervisor,reread']);
            Route::post('/update', [SupervisorController::class, 'update'])
                ->middleware(['admin.permission:ops.supervisor.control', 'admin.audit:ops.supervisor,update']);
        });

        Route::prefix('docker')->group(function (): void {
            Route::get('/summary', [DockerController::class, 'summary'])
                ->middleware('admin.permission:ops.docker.view');
            Route::get('/containers', [DockerController::class, 'containers'])
                ->middleware('admin.permission:ops.docker.view');
            Route::get('/stats/{id}', [DockerController::class, 'stats'])
                ->middleware('admin.permission:ops.docker.view');
            Route::get('/logs/{id}', [DockerController::class, 'logs'])
                ->middleware('admin.permission:ops.docker.view');
            Route::get('/version', [DockerController::class, 'version'])
                ->middleware('admin.permission:ops.docker.view');
            Route::post('/restart/{id}', [DockerController::class, 'restart'])
                ->middleware(['admin.permission:ops.docker.control', 'admin.audit:ops.docker,restart']);
            Route::post('/start/{id}', [DockerController::class, 'start'])
                ->middleware(['admin.permission:ops.docker.control', 'admin.audit:ops.docker,start']);
            Route::post('/stop/{id}', [DockerController::class, 'stop'])
                ->middleware(['admin.permission:ops.docker.control', 'admin.audit:ops.docker,stop']);
        });

        Route::prefix('system')
            ->middleware('admin.permission:ops.system.view')
            ->group(function (): void {
                Route::get('/summary', [SystemMonitorController::class, 'summary']);
                Route::get('/advanced', [AdvancedSystemController::class, 'summary']);
                Route::get('/disk', [DiskController::class, 'index']);
                Route::get('/disk/push', [DiskPushController::class, 'push']);
            });

        Route::get('/network', [NetworkController::class, 'index'])
            ->middleware('admin.permission:ops.system.view');

        Route::prefix('coin-assistant')
            ->middleware('admin.permission:ops.system.view')
            ->group(function (): void {
                Route::get('/summary', [DailyCoinAssistantController::class, 'summary']);
                Route::post('/reminder', [DailyCoinAssistantController::class, 'reminder'])
                    ->middleware('admin.audit:ops.coin_assistant,reminder');
                Route::post('/confirm', [DailyCoinAssistantController::class, 'confirm'])
                    ->middleware('admin.audit:ops.coin_assistant,confirm');
                Route::post('/automation-request', [DailyCoinAssistantController::class, 'automationRequest'])
                    ->middleware('admin.audit:ops.coin_assistant,automation_request');
            });

        Route::prefix('alerts')
            ->middleware('admin.permission:ops.alerts.view')
            ->group(function (): void {
                Route::get('/', [AlertController::class, 'index']);
                Route::get('/summary', [AlertController::class, 'summary']);
                Route::get('/notification-status', [AlertController::class, 'notificationStatus']);
                Route::post('/evaluate', [AlertController::class, 'evaluate'])
                    ->middleware(['admin.permission:ops.alerts.manage', 'admin.audit:ops.alerts,evaluate']);
                Route::post('/test-notification', [AlertController::class, 'testNotification'])
                    ->middleware(['admin.permission:ops.alerts.manage', 'admin.audit:ops.alerts,test_notification']);
                Route::post('/demo-scenarios', [AlertController::class, 'demoScenarios'])
                    ->middleware(['admin.permission:ops.alerts.manage', 'admin.audit:ops.alerts,demo_scenarios']);
                Route::post('/{alert}/acknowledge', [AlertController::class, 'acknowledge'])
                    ->middleware(['admin.permission:ops.alerts.manage', 'admin.audit:ops.alerts,acknowledge']);
                Route::post('/{alert}/resolve', [AlertController::class, 'resolve'])
                    ->middleware(['admin.permission:ops.alerts.manage', 'admin.audit:ops.alerts,resolve']);
            });

        Route::prefix('logs')
            ->middleware('admin.permission:ops.logs.view')
            ->group(function (): void {
                Route::get('/laravel', [LogController::class, 'laravel']);
                Route::get('/octane', [LogController::class, 'octane']);
                Route::get('/redis', [LogController::class, 'redis']);
                Route::get('/system', [LogController::class, 'system']);
                Route::get('/system/sources', [LogController::class, 'systemSources']);
                Route::get('/docker', [LogController::class, 'docker']);
            });

        Route::get('/test-broadcast', function () {
            broadcast(new TestEvent([
                'hello' => 'world',
                'time' => now()->toDateTimeString(),
            ]));

            return [
                'success' => true,
            ];
        })->middleware('admin.permission:ops.system.view');
    });
