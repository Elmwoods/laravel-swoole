<?php

use App\Http\Middleware\AdminAuditMiddleware;
use App\Http\Middleware\AdminAuthenticate;
use App\Http\Middleware\AdminPermissionMiddleware;
use App\Services\Admin\AdminTrustedDeviceService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
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
         | 可信反向代理
         |--------------------------------------------------------------------------
         | 后台登录 IP 白/黑名单要按真实客户端 IP 生效，Octane/Swoole 常在 nginx 之后，
         | 必须信任代理并读取 X-Forwarded-For。默认 OPS_TRUSTED_PROXIES 为空 = 不信任
         | 任何代理（保持现状行为），配置后 request->ip() 全局回归真实客户端 IP。
         |
         | 注意：本闭包在应用构建期运行，config 服务尚未绑定，故直接读环境变量
         | （OPS_TRUSTED_PROXIES 应作为真实环境变量注入，逗号分隔的 IP/CIDR，或单个 '*'）。
         */
        $trustedProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('OPS_TRUSTED_PROXIES', '')),
        )));
        if ($trustedProxies !== []) {
            $middleware->trustProxies(
                at: (count($trustedProxies) === 1 && $trustedProxies[0] === '*') ? '*' : $trustedProxies,
                headers: Request::HEADER_X_FORWARDED_FOR
                    | Request::HEADER_X_FORWARDED_HOST
                    | Request::HEADER_X_FORWARDED_PORT
                    | Request::HEADER_X_FORWARDED_PROTO,
            );
        }

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
