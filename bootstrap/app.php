<?php

use App\Http\Middleware\AdminAuditMiddleware;
use App\Http\Middleware\AdminAuthenticate;
use App\Http\Middleware\AdminPermissionMiddleware;
use App\Services\Admin\AdminTrustedDeviceService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(
    basePath: dirname(__DIR__)
)

    ->withRouting(

        web: __DIR__.'/../routes/web.php',

        api: __DIR__.'/../routes/api.php',

        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',

        health: '/up',

        //        /*
        //         |--------------------------------------------------------------------------
        //         | Admin 路由
        //         |--------------------------------------------------------------------------
        //         */
        //
        //        then: function () {
        //
        //            Route::middleware('web')
        //                ->group(base_path('routes/admin.php'));
        //
        //        }

    )

    ->withCommands()

    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin.auth' => AdminAuthenticate::class,
            'admin.permission' => AdminPermissionMiddleware::class,
            'admin.audit' => AdminAuditMiddleware::class,
        ]);

        /*
         |--------------------------------------------------------------------------
         | 受信任设备 cookie 不做加密
         |--------------------------------------------------------------------------
         | 该 cookie 只承载一个高熵随机 token，服务端仅保存其 SHA-256 摘要，
         | 明文无法反查任何数据；保持不加密以便契约清晰，安全性由 httpOnly +
         | secure + 过期 + 可撤销保证。
         */
        $middleware->encryptCookies(except: [
            AdminTrustedDeviceService::COOKIE_NAME,
        ]);

        /*
         |--------------------------------------------------------------------------
         | 排除 Ops Center CSRF
         |--------------------------------------------------------------------------
         */
        //
        //        $middleware->validateCsrfTokens(
        //
        //            except: [
        //
        //                'admin/ops/*',
        //
        //            ]
        //
        //        );

    })

    ->withExceptions(function (Exceptions $exceptions): void {})

    ->create();
