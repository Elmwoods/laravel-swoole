<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Configuration\Exceptions;

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
            'admin.auth' => \App\Http\Middleware\AdminAuthenticate::class,
            'admin.permission' => \App\Http\Middleware\AdminPermissionMiddleware::class,
            'admin.audit' => \App\Http\Middleware\AdminAuditMiddleware::class,
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

    ->withExceptions(function (Exceptions $exceptions): void {

    })

    ->create();
